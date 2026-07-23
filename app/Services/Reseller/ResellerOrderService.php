<?php

namespace App\Services\Reseller;

use App\Models\Area;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Services\Admin\CustomerLookupService;
use App\Services\Orders\OrderAdjustmentSync;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Services\Storefront\CheckoutPricing;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class ResellerOrderService
{
    public function __construct(
        private OrderStockService $stock,
        private CustomerLookupService $customers,
        private OrderAdjustmentSync $adjustmentSync,
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * Create a reseller order.
     *
     * Each line must include:
     *   product_id, name, quantity, price (sell price ≥ base_price),
     *   base_price, commission_rate, line_total, product_image
     *
     * @param  array<string, mixed>  $orderData
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,base_price:float,commission_rate:float,line_total:float,product_image:?string}>  $lines
     */
    public function create(array $orderData, array $lines): Order
    {
        return DB::transaction(function () use ($orderData, $lines) {
            $resellerId = auth()->id();

            $orderData = $this->attachCustomerUser($orderData);

            $newQuantities = $this->stock->quantitiesFromLines($lines);
            $this->stock->syncQuantities([], $newQuantities);

            $order = Order::query()->create(array_merge($orderData, [
                'order_number' => 'PENDING',
                'reseller_id' => $resellerId,
                'created_by' => $resellerId,
                'updated_by' => $resellerId,
            ]));

            $order->update(['order_number' => (string) $order->id]);

            $this->persistLines($order, $lines);

            $order = $order->fresh(['items']);
            $this->adjustmentSync->materializeFromScalars($order);
            $this->paymentSync->sync($order->fresh());

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status' => $order->status,
                'note' => 'Order created by reseller.',
                'changed_by' => $resellerId,
                'created_at' => $order->placed_at ?? now(),
            ]);

            return $order->fresh(['items', 'adjustments', 'paymentTransactions']);
        });
    }

    /**
     * Build order attributes array ready for create().
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function orderAttributesFromForm(array $validated): array
    {
        $subtotal = (int) round((float) $validated['subtotal']);
        $deliveryCharge = (int) round((float) $validated['delivery_charge']);
        $total = max(0, $subtotal + $deliveryCharge);

        return [
            'name' => $validated['name'],
            'phone' => PhoneNumber::display($validated['phone']),
            'email' => null,
            'address' => $validated['address'],
            'area' => $validated['area'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'delivery_type' => 'home',
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'charge' => 0,
            'discount' => 0,
            'total' => $total,
            'cod_amount' => $total,
            'due_amount' => $total,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'admin_note' => null,
            'courier_note' => null,
            'customer_note' => null,
            'is_replacement' => false,
            'has_return' => false,
            'placed_at' => now(),
        ];
    }

    /**
     * Compute estimated commission for a line at current sell price.
     */
    public static function estimatedLineCommission(float $sellPrice, float $basePrice, float $commissionRate, int $qty): float
    {
        $markupPerUnit = max(0.0, $sellPrice - $basePrice);

        // Integer taka only — match credited commission rounding.
        return (float) (int) round(($commissionRate + $markupPerUnit) * $qty);
    }

    /**
     * @param  list<array{product_id:int,name:string,quantity:int,price:float,base_price:float,commission_rate:float,line_total:float,product_image:?string}>  $lines
     */
    private function persistLines(Order $order, array $lines): void
    {
        $productCaps = Product::query()
            ->whereIn('id', collect($lines)->pluck('product_id')->filter()->unique()->all())
            ->pluck('max_discount', 'id');

        foreach ($lines as $line) {
            $maxDiscount = $productCaps[$line['product_id']] ?? null;

            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'product_image' => $line['product_image'],
                'quantity' => $line['quantity'],
                'base_price' => (float) $line['base_price'],
                'price' => (float) $line['price'],
                'purchase_price' => (float) ($line['purchase_price'] ?? 0),
                'commission_rate' => (float) $line['commission_rate'],
                'commission_earned' => 0,
                'max_discount' => $maxDiscount !== null ? (float) $maxDiscount : null,
                'line_total' => (float) $line['line_total'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @return array<string, mixed>
     */
    private function attachCustomerUser(array $orderData): array
    {
        $phone = (string) ($orderData['phone'] ?? '');
        $name = (string) ($orderData['name'] ?? '');

        $user = $this->customers->findOrCreateCustomer($phone, $name, null);

        if ($user) {
            $orderData['user_id'] = $user->id;
        }

        return $orderData;
    }

    /**
     * Compute delivery charge using CheckoutPricing for a given city/area and cart.
     */
    public static function deliveryCharge(?int $areaId, ?int $cityId, int $itemCount, float $subtotal): float
    {
        if ($itemCount <= 0 || $subtotal <= 0) {
            return 0.0;
        }

        $location = $areaId
            ? Area::query()->find($areaId)
            : ($cityId ? City::query()->find($cityId) : null);

        return (float) CheckoutPricing::deliveryCharge($location, $itemCount, $subtotal);
    }
}

