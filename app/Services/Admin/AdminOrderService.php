<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Services\Orders\OrderAdjustmentAuditor;
use App\Services\Orders\OrderAdjustmentSync;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class AdminOrderService
{
    public function __construct(
        private OrderStockService $stock,
        private OrderStatusService $statusHistory,
        private CustomerLookupService $customers,
        private OrderAdjustmentSync $adjustmentSync,
        private OrderAdjustmentAuditor $auditor,
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * @param  array<string, mixed>  $orderData
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string}>  $lines
     */
    public function create(array $orderData, array $lines): Order
    {
        return DB::transaction(function () use ($orderData, $lines) {
            $orderData = $this->attachCustomerUser($orderData);

            $newQuantities = $this->stock->quantitiesFromLines($lines);
            $this->stock->syncQuantities([], $newQuantities);

            $order = Order::query()->create(array_merge($orderData, [
                'order_number' => 'PENDING',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            $order->update(['order_number' => (string) $order->id]);

            $this->persistLines($order, $lines);

            $this->syncMoneyComponents($order, $orderData);

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
        return DB::transaction(function () use ($order, $orderData, $lines) {
            $order->load('items');

            $orderData = $this->attachCustomerUser($orderData);

            $changeSummary = $this->summarizeUpdateChanges($order, $orderData, $lines);

            $oldDeliveryCharge = (float) $order->delivery_charge;

            $oldQuantities = $this->stock->quantitiesFromOrder($order);
            $newQuantities = $this->stock->quantitiesFromLines($lines);

            $this->stock->syncQuantities($oldQuantities, $newQuantities);

            $order->update(array_merge($orderData, [
                'updated_by' => auth()->id(),
            ]));

            $order->items()->delete();
            $this->persistLines($order, $lines);

            $order = $order->fresh(['items']);

            $this->syncMoneyComponents($order, $orderData, $oldDeliveryCharge);

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
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,base_price?:float,commission_rate?:float}>  $lines
     */
    private function persistLines(Order $order, array $lines): void
    {
        $productCaps = Product::query()
            ->whereIn('id', collect($lines)->pluck('product_id')->filter()->unique()->all())
            ->pluck('max_discount', 'id');

        foreach ($lines as $line) {
            $basePrice = array_key_exists('base_price', $line)
                ? (float) $line['base_price']
                : (float) $line['price'];
            $commissionRate = array_key_exists('commission_rate', $line)
                ? (float) $line['commission_rate']
                : 0.0;
            $maxDiscount = array_key_exists('max_discount', $line)
                ? $line['max_discount']
                : ($productCaps[$line['product_id']] ?? null);

            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'product_image' => $line['product_image'],
                'quantity' => $line['quantity'],
                'base_price' => $basePrice,
                'price' => $line['price'],
                'purchase_price' => $line['purchase_price'],
                'commission_rate' => $commissionRate,
                'commission_earned' => 0,
                'max_discount' => $maxDiscount !== null ? (float) $maxDiscount : null,
                'line_total' => $line['line_total'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $orderData
     */
    private function syncMoneyComponents(Order $order, array $orderData, ?float $oldDeliveryCharge = null): void
    {
        if ($oldDeliveryCharge !== null) {
            $newDelivery = (float) ($orderData['delivery_charge'] ?? $order->delivery_charge);

            if (round($oldDeliveryCharge, 2) !== round($newDelivery, 2)) {
                $this->auditor->logField($order, [
                    'field' => 'delivery_charge',
                    'phase' => 'admin_edit',
                    'amount_before' => $oldDeliveryCharge,
                    'amount_after' => $newDelivery,
                    'note' => 'Customer delivery charge updated from admin.',
                ], auth()->user());
            }
        }

        $lines = $this->buildAdjustmentLinesFromScalars($orderData);
        $this->adjustmentSync->replaceAdjustments($order->fresh(['items']), $lines, auth()->user());
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @return list<array{type:string,label:string,amount:float,source:string,sort_order:int}>
     */
    private function buildAdjustmentLinesFromScalars(array $orderData): array
    {
        $lines = [];
        $charge = (float) ($orderData['charge'] ?? 0);
        $discount = (float) ($orderData['discount'] ?? 0);

        if ($charge > 0) {
            $lines[] = [
                'type' => 'charge',
                'label' => 'Charge',
                'amount' => $charge,
                'source' => 'admin',
                'sort_order' => 10,
            ];
        }

        if ($discount > 0) {
            $lines[] = [
                'type' => 'discount',
                'label' => 'Discount',
                'amount' => $discount,
                'source' => 'admin',
                'sort_order' => 20,
            ];
        }

        return $lines;
    }

    /**
     * Link order to an existing customer by phone, or create one (no duplicates).
     *
     * @param  array<string, mixed>  $orderData
     * @return array<string, mixed>
     */
    private function attachCustomerUser(array $orderData): array
    {
        $phone = (string) ($orderData['phone'] ?? '');
        $name = (string) ($orderData['name'] ?? '');
        $email = isset($orderData['email']) ? (string) $orderData['email'] : null;

        $user = $this->customers->findOrCreateCustomer($phone, $name, $email);

        if ($user) {
            $orderData['user_id'] = $user->id;
        }

        return $orderData;
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
    public static function orderAttributesFromForm(array $validated, bool $isUpdate = false): array
    {
        $subtotal = (int) round((float) $validated['subtotal']);
        $deliveryCharge = (int) round((float) $validated['delivery_charge']);
        $charge = (int) round((float) ($validated['charge'] ?? 0));
        $discount = (int) round((float) $validated['discount']);
        $total = max(0, $subtotal + $deliveryCharge + $charge - $discount);

        $attributes = [
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
            'payment_method' => $validated['payment_method'] ?? 'cod',
            'status' => $validated['status'] ?? 'new',
            'admin_note' => $validated['admin_note'] ?? null,
            'courier_note' => $validated['courier_note'] ?? null,
            'customer_note' => $validated['customer_note'] ?? null,
            'is_replacement' => (bool) ($validated['is_replacement'] ?? false),
            'has_return' => (bool) ($validated['has_return'] ?? false),
            'placed_at' => $validated['placed_at'] ?? now(),
        ];

        if (! $isUpdate) {
            $attributes['cod_amount'] = $total;
            $attributes['due_amount'] = $total;
            $attributes['payment_status'] = 'unpaid';
        }

        return $attributes;
    }
}
