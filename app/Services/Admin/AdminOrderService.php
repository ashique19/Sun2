<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Services\Orders\OrderStockService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOrderService
{
    public function __construct(
        private OrderStockService $stock,
        private OrderStatusService $statusHistory,
    ) {}

    /**
     * @param  array<string, mixed>  $orderData
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string}>  $lines
     */
    public function create(array $orderData, array $lines): Order
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product to the order.',
            ]);
        }

        return DB::transaction(function () use ($orderData, $lines) {
            $newQuantities = $this->stock->quantitiesFromLines($lines);
            $this->stock->syncQuantities([], $newQuantities);

            $order = Order::query()->create(array_merge($orderData, [
                'order_number' => 'PENDING',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            $order->update(['order_number' => (string) $order->id]);

            $this->persistLines($order, $lines);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status' => $order->status,
                'note' => 'Order created from admin.',
                'changed_by' => auth()->id(),
                'created_at' => $order->placed_at ?? now(),
            ]);

            return $order->fresh(['items']);
        });
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string}>  $lines
     */
    public function update(Order $order, array $orderData, array $lines): Order
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product to the order.',
            ]);
        }

        return DB::transaction(function () use ($order, $orderData, $lines) {
            $order->load('items');

            $changeSummary = $this->summarizeUpdateChanges($order, $orderData, $lines);

            $oldQuantities = $this->stock->quantitiesFromOrder($order);
            $newQuantities = $this->stock->quantitiesFromLines($lines);

            $this->stock->syncQuantities($oldQuantities, $newQuantities);

            $order->update(array_merge($orderData, [
                'updated_by' => auth()->id(),
            ]));

            $order->items()->delete();
            $this->persistLines($order, $lines);

            $order = $order->fresh(['items']);

            if ($changeSummary !== null) {
                $this->statusHistory->record($order, $changeSummary);
            }

            return $order;
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->load('items');
            $this->stock->releaseOrder($order);
            $order->delete();
        });
    }

    /**
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string}>  $lines
     */
    private function persistLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'product_image' => $line['product_image'],
                'quantity' => $line['quantity'],
                'price' => $line['price'],
                'purchase_price' => $line['purchase_price'],
                'line_total' => $line['line_total'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string}>  $lines
     */
    private function summarizeUpdateChanges(Order $order, array $orderData, array $lines): ?string
    {
        $parts = [];

        $customerFields = ['name', 'phone'];
        foreach ($customerFields as $field) {
            if (array_key_exists($field, $orderData) && (string) $order->{$field} !== (string) $orderData[$field]) {
                $parts[] = 'customer details changed';
                break;
            }
        }

        $addressFields = ['address', 'city', 'area'];
        foreach ($addressFields as $field) {
            if (array_key_exists($field, $orderData) && (string) ($order->{$field} ?? '') !== (string) ($orderData[$field] ?? '')) {
                $parts[] = 'address changed';
                break;
            }
        }

        $oldLineCount = $order->items->count();
        $newLineCount = count($lines);
        $oldSignature = $order->items
            ->map(fn (OrderProduct $item) => (int) $item->product_id.':'.(int) $item->quantity.':'.round((float) $item->price, 2))
            ->sort()
            ->values()
            ->all();
        $newSignature = collect($lines)
            ->map(fn (array $line) => (int) $line['product_id'].':'.(int) $line['quantity'].':'.round((float) $line['price'], 2))
            ->sort()
            ->values()
            ->all();

        if ($oldSignature !== $newSignature) {
            $parts[] = "items updated ({$oldLineCount} → {$newLineCount} lines)";
        }

        $oldTotal = round((float) $order->total, 2);
        $newTotal = round((float) ($orderData['total'] ?? $oldTotal), 2);
        $moneyChanged = false;
        foreach (['subtotal', 'delivery_charge', 'charge', 'discount', 'payment_method'] as $field) {
            if (! array_key_exists($field, $orderData)) {
                continue;
            }
            $old = $field === 'payment_method'
                ? (string) ($order->{$field} ?? '')
                : round((float) $order->{$field}, 2);
            $new = $field === 'payment_method'
                ? (string) $orderData[$field]
                : round((float) $orderData[$field], 2);
            if ($old !== $new) {
                $moneyChanged = true;
                break;
            }
        }
        if ($moneyChanged || $oldTotal !== $newTotal) {
            $parts[] = 'total ৳'.number_format($oldTotal, 0).' → ৳'.number_format($newTotal, 0);
        }

        foreach (['admin_note' => 'admin note', 'courier_note' => 'courier note'] as $field => $label) {
            if (! array_key_exists($field, $orderData)) {
                continue;
            }
            if ((string) ($order->{$field} ?? '') !== (string) ($orderData[$field] ?? '')) {
                $parts[] = "{$label} changed";
            }
        }

        if ($parts === []) {
            return null;
        }

        return 'Order updated from admin: '.implode('; ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function orderAttributesFromForm(array $validated): array
    {
        $subtotal = (int) round((float) $validated['subtotal']);
        $deliveryCharge = (int) round((float) $validated['delivery_charge']);
        $charge = (int) round((float) ($validated['charge'] ?? 0));
        $discount = (int) round((float) $validated['discount']);
        $total = max(0, $subtotal + $deliveryCharge + $charge - $discount);

        return [
            'name' => $validated['name'],
            'phone' => PhoneNumber::display($validated['phone']),
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'],
            'area' => $validated['area'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'delivery_type' => 'home',
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'charge' => $charge,
            'discount' => $discount,
            'total' => $total,
            'cod_amount' => $total,
            'due_amount' => $total,
            'payment_status' => 'unpaid',
            'payment_method' => $validated['payment_method'] ?? 'cod',
            'status' => $validated['status'] ?? 'new',
            'admin_note' => $validated['admin_note'] ?? null,
            'courier_note' => $validated['courier_note'] ?? null,
            'customer_note' => $validated['customer_note'] ?? null,
            'is_replacement' => (bool) ($validated['is_replacement'] ?? false),
            'has_return' => (bool) ($validated['has_return'] ?? false),
            'placed_at' => $validated['placed_at'] ?? now(),
        ];
    }
}
