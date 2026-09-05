<?php

namespace App\Services\Storefront;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Admin\OrderStatusService;
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

            $accountUser = app(GuestCheckoutAccountService::class)->resolveCheckoutAccount($customer);
            $userId = $accountUser?->id ?? auth()->id();

            $order = Order::query()->create([
                'order_number' => 'PENDING',
                'user_id' => $userId,
                'created_by' => $userId,
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

            // #region agent log
            file_put_contents('/opt/cursor/logs/debug.log', json_encode([
                'id' => 'log_placer_create_'.uniqid(),
                'timestamp' => (int) (microtime(true) * 1000),
                'location' => 'OrderPlacer.php:place:afterCreate',
                'message' => 'Storefront order created (pre adjustments/payment sync)',
                'hypothesisId' => 'A,D',
                'data' => [
                    'order_id' => $order->id,
                    'pricing_total' => $pricing->total,
                    'pricing_subtotal' => $pricing->subtotal,
                    'pricing_delivery' => $pricing->deliveryCharge,
                    'pricing_discount' => $pricing->discount,
                    'adjustment_line_count' => count($pricing->adjustmentLines),
                    'total' => (float) $order->total,
                    'paid_amount' => (float) ($order->paid_amount ?? 0),
                    'due_amount' => (float) $order->due_amount,
                    'cod_amount' => (float) $order->cod_amount,
                    'payment_status' => $order->payment_status,
                ],
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            // #endregion

            $order->update(['order_number' => (string) $order->id]);

            app(OrderStatusService::class)->recordPlacement($order);

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
                    'unit_cost' => $product->effectiveUnitCost(),
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
                $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

                if (! $locked->hasUsesRemaining()) {
                    throw new \RuntimeException(
                        "Coupon '{$locked->code}' has reached its usage limit."
                    );
                }

                $locked->increment('used_count');
            }

            $cart->clear();
            session()->forget('checkout.coupon_code');
            session()->forget('checkout.coupon_codes');
            session(['checkout.last_order_id' => $order->id]);

            $order = $order->fresh(['items', 'adjustments', 'paymentTransactions']);

            // #region agent log
            $bill = $order->moneyTotals()->billToCustomer;
            $collectable = $order->collectableAmount();
            file_put_contents('/opt/cursor/logs/debug.log', json_encode([
                'id' => 'log_placer_return_'.uniqid(),
                'timestamp' => (int) (microtime(true) * 1000),
                'location' => 'OrderPlacer.php:place:beforeReturn',
                'message' => 'Storefront order ready to return',
                'hypothesisId' => 'A,B,C,D',
                'data' => [
                    'order_id' => $order->id,
                    'total' => (float) $order->total,
                    'paid_amount' => (float) ($order->paid_amount ?? 0),
                    'due_amount' => (float) $order->due_amount,
                    'cod_amount' => (float) $order->cod_amount,
                    'payment_status' => $order->payment_status,
                    'billToCustomer' => $bill,
                    'collectable' => $collectable,
                    'bill_vs_total_diverged' => abs($bill - (float) $order->total) >= 0.01,
                    'collectable_mismatch' => abs($collectable - $bill) >= 0.01,
                    'payment_txn_count' => $order->paymentTransactions->count(),
                    'item_count' => $order->items->count(),
                    'adjustment_count' => $order->adjustments->count(),
                ],
            ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            // #endregion

            if ($accountUser !== null && auth()->guest()) {
                app(GuestCheckoutAccountService::class)->login($accountUser);
            }

            return $order;
        });
    }
}
