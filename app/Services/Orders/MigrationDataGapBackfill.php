<?php

namespace App\Services\Orders;

use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PaymentTransaction;
use App\Models\Product;

/**
 * Repair sun2 money/cost gaps left by legacy→sun2 ETL (and early post-cutover rows).
 *
 * Safe defaults never invent cash collection. Courier fees are optional estimates.
 */
class MigrationDataGapBackfill
{
    public function __construct(private OrderPaymentSync $paymentSync) {}

    /**
     * @return array{
     *     purchase_price_lines: int,
     *     settlement_orders: int,
     *     courier_estimate_orders: int,
     *     reclassified_orders: int
     * }
     */
    public function run(
        bool $dryRun = false,
        bool $purchasePrice = true,
        bool $settlement = true,
        bool $estimateCourier = false,
        bool $reclassifyUnsettledDelivered = false,
    ): array {
        return [
            'purchase_price_lines' => $purchasePrice
                ? $this->backfillPurchasePrices($dryRun)
                : 0,
            'settlement_orders' => $settlement
                ? $this->backfillSettlementFromCollected($dryRun)
                : 0,
            'courier_estimate_orders' => $estimateCourier
                ? $this->estimateCourierCharges($dryRun)
                : 0,
            'reclassified_orders' => $reclassifyUnsettledDelivered
                ? $this->reclassifyUnsettledDelivered($dryRun)
                : 0,
        ];
    }

    /**
     * When a line snapshotted purchase_price=0 but the catalog product now has cost, copy it.
     * Proxy only — catalog cost may differ from historical cost.
     */
    public function backfillPurchasePrices(bool $dryRun = false): int
    {
        $query = OrderProduct::query()
            ->where('order_products.purchase_price', 0)
            ->whereNotNull('order_products.product_id')
            ->whereHas('product', fn ($q) => $q->where('purchase_price', '>', 0));

        $count = (clone $query)->count();

        if ($dryRun || $count === 0) {
            return $count;
        }

        $updated = 0;

        $query->orderBy('id')->chunkById(500, function ($lines) use (&$updated): void {
            $productIds = $lines->pluck('product_id')->unique()->filter()->values()->all();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'purchase_price', 'unit_cost'])
                ->keyBy('id');

            foreach ($lines as $line) {
                $product = $products->get($line->product_id);

                if ($product === null || (float) $product->purchase_price <= 0) {
                    continue;
                }

                $purchase = round((float) $product->purchase_price, 2);
                $unitCost = (float) $product->unit_cost > 0
                    ? round((float) $product->unit_cost, 2)
                    : $purchase;

                $line->forceFill([
                    'purchase_price' => $purchase,
                    'unit_cost' => $unitCost,
                ])->save();

                $updated++;
            }
        });

        return $updated;
    }

    /**
     * Rebuild payment ledger + scalars from existing collected_amount when paid_amount is empty.
     * Does not invent collection for delivered orders with collected_amount = 0.
     */
    public function backfillSettlementFromCollected(bool $dryRun = false): int
    {
        $candidates = Order::query()
            ->where('status', 'delivered')
            ->where('collected_amount', '>', 0)
            ->where('paid_amount', 0)
            ->whereDoesntHave('paymentTransactions')
            ->orderBy('id');

        $count = (clone $candidates)->count();

        if ($dryRun || $count === 0) {
            return $count;
        }

        $synced = 0;

        $candidates->chunkById(200, function ($orders) use (&$synced): void {
            foreach ($orders as $order) {
                $externalId = 'legacy-collected-'.$order->id;

                $exists = PaymentTransaction::query()
                    ->where('method', 'cod')
                    ->where('external_id', $externalId)
                    ->exists();

                if (! $exists) {
                    PaymentTransaction::query()->create([
                        'order_id' => $order->id,
                        'method' => 'cod',
                        'amount' => round((float) $order->collected_amount, 2),
                        'reference' => 'legacy-collected-backfill',
                        'status' => 'completed',
                        'kind' => 'settlement',
                        'paid_at' => $order->actual_delivery_date
                            ?? $order->payment_date
                            ?? $order->placed_at
                            ?? $order->created_at
                            ?? now(),
                        'external_id' => $externalId,
                        'meta' => [
                            'source' => 'migration_data_gap_backfill',
                            'from' => 'orders.collected_amount',
                        ],
                    ]);
                }

                $this->paymentSync->sync($order->fresh(['paymentTransactions']));
                $synced++;
            }
        });

        return $synced;
    }

    /**
     * Estimate merchant courier fee from courier rate card when courier_charge is still 0.
     * Labelled estimate — not audited Steadfast invoice data.
     */
    public function estimateCourierCharges(bool $dryRun = false): int
    {
        $couriers = Courier::query()->get(['id', 'charge', 'osd_charge'])->keyBy('id');

        $candidates = Order::query()
            ->where('courier_charge', 0)
            ->whereNotNull('courier_id')
            ->whereIn('status', ['delivered', 'dispatched', 'returned'])
            ->orderBy('id');

        $count = 0;
        $updates = [];

        $candidates->chunkById(500, function ($orders) use ($couriers, $dryRun, &$count, &$updates): void {
            foreach ($orders as $order) {
                $courier = $couriers->get($order->courier_id);

                if ($courier === null) {
                    continue;
                }

                $inside = (float) $courier->charge;
                $outside = (float) $courier->osd_charge;
                $fee = (float) $order->delivery_charge >= 100.0
                    ? ($outside > 0 ? $outside : $inside)
                    : ($inside > 0 ? $inside : $outside);

                if ($fee <= 0) {
                    continue;
                }

                $count++;

                if (! $dryRun) {
                    $updates[$order->id] = round($fee, 2);
                }
            }

            if ($dryRun || $updates === []) {
                $updates = [];

                return;
            }

            foreach ($updates as $orderId => $fee) {
                Order::query()->whereKey($orderId)->update([
                    'courier_charge' => $fee,
                    'updated_at' => now(),
                ]);
            }

            $updates = [];
        });

        return $count;
    }

    /**
     * Reclassify "delivered" with no collection and no delivery date back to dispatched.
     * These usually come from mid-flight legacy status without settlement.
     */
    public function reclassifyUnsettledDelivered(bool $dryRun = false): int
    {
        $query = Order::query()
            ->where('status', 'delivered')
            ->where('collected_amount', 0)
            ->where('total', '>', 0)
            ->whereNull('actual_delivery_date');

        $count = (clone $query)->count();

        if ($dryRun || $count === 0) {
            return $count;
        }

        return (int) $query->update([
            'status' => 'dispatched',
            'updated_at' => now(),
        ]);
    }
}
