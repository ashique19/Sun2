<?php

namespace App\Services\Storefront;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Orders\OrderAdjustmentSync;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Support\PhoneNumber;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\DB;

class OrderPlacer
{
    /**
     * @param  list<Coupon>  $coupons
     */
    public function place(
        CartService $cart,
        CheckoutPricing $pricing,
        array $customer,
        array $coupons = [],
    ): Order {
        return DB::transaction(function () use ($cart, $pricing, $customer, $coupons) {
            $lines = $cart->lines();

            if ($lines->isEmpty()) {
                throw new \RuntimeException('Cart is empty.');
            }

            $order = Order::query()->create([
                'order_number' => 'PENDING',
                'user_id' => auth()->id(),
                'created_by' => auth()->id(),
                'placed_via' => Order::PLACED_VIA_STOREFRONT,
                'name' => $customer['name'],
                'phone' => PhoneNumber::display($customer['phone']),
                'email' => $customer['email'] ?: null,
                'address' => $customer['address'],
                'area' => $customer['area'] ?: null,
                'city' => $customer['city'],
                'state' => $customer['state'] ?? 'Dhaka',
                'postcode' => $customer['postcode'] ?? null,
                'delivery_type' => 'home',
                'subtotal' => $pricing->subtotal,
                'delivery_charge' => $pricing->deliveryCharge,
                'discount' => $pricing->discount,
                'coupon_id' => $pricing->couponId,
                'total' => $pricing->total,
                'cod_amount' => $pricing->total,
                'due_amount' => $pricing->total,
                'payment_status' => 'unpaid',
                'payment_method' => 'cod',
                'status' => 'new',
                'customer_note' => $customer['customer_note'] ?? null,
                'reseller_id' => $customer['reseller_id'] ?? null,
                'placed_at' => now(),
            ]);

            $order->update(['order_number' => (string) $order->id]);

            app(\App\Services\Admin\OrderStatusService::class)->recordPlacement($order);

            foreach ($lines as $line) {
                $product = $line['product'];

                app(OrderStockService::class)->reserve($product->id, $line['quantity']);

                OrderProduct::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'product_image' => StorefrontAssets::url($product->primaryImagePath()),
                    'quantity' => $line['quantity'],
                    'price' => $product->price,
                    'purchase_price' => $product->purchase_price,
                    'max_discount' => $product->max_discount !== null
                        ? (float) $product->max_discount
                        : null,
                    'line_total' => $line['line_total'],
                ]);
            }

            $adjustmentSync = app(OrderAdjustmentSync::class);

            if ($pricing->adjustmentLines !== []) {
                $adjustmentSync->replaceAdjustments($order->fresh(['items']), $pricing->adjustmentLines);
            } else {
                $adjustmentSync->materializeFromScalars($order->fresh(['items']));
            }

            app(OrderPaymentSync::class)->sync($order->fresh());

            foreach (collect($coupons)->unique('id') as $coupon) {
                $coupon->increment('used_count');
            }

            $cart->clear();
            session()->forget('checkout.coupon_code');
            session()->forget('checkout.coupon_codes');
            session(['checkout.last_order_id' => $order->id]);

            return $order->fresh(['items', 'adjustments', 'paymentTransactions']);
        });
    }
}
