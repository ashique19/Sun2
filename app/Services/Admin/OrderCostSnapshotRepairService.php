<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class OrderCostSnapshotRepairService
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private ProductUnitCostService $productCosts,
    ) {}

    public function eligibleOrderCount(): int
    {
        return (int) Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->count();
    }

    /**
     * Scan the next batch of orders (by id) and repair cost-snapshot mistakes.
     *
     * - Open orders: if a line has ~৳0 cost but its linked product has a cost, copy the product snapshot.
     * - Cancelled/returned: if contribution COGS is still > 0 (usually missing returned_quantity after a
     *   bad backfill), clear line cost snapshots so P/L is not inventing COGS on dead orders.
     *
     * @return array{
     *     scanned: int,
     *     fixed_orders: int,
     *     backfilled_lines: int,
     *     cleared_return_lines: int,
     *     next_after_id: int,
     *     done: bool,
     *     sample_order_numbers: list<string>
     * }
     */
    public function repairNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with(['items.product'])
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $fixedOrders = 0;
        $backfilled = 0;
        $cleared = 0;
        $samples = [];

        foreach ($orders as $order) {
            $result = $this->repairOrder($order);

            if ($result['changed']) {
                $fixedOrders++;
                $backfilled += $result['backfilled_lines'];
                $cleared += $result['cleared_return_lines'];
                if (count($samples) < 8) {
                    $samples[] = (string) $order->order_number;
                }
            }
        }

        $lastId = $orders->last()?->id ?? $afterId;

        return [
            'scanned' => $orders->count(),
            'fixed_orders' => $fixedOrders,
            'backfilled_lines' => $backfilled,
            'cleared_return_lines' => $cleared,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_order_numbers' => $samples,
        ];
    }

    /**
     * Apply COGS snapshot repair for a single order (open backfill or return clear).
     *
     * @return array{changed: bool, backfilled_lines: int, cleared_return_lines: int}
     */
    public function repairOrder(Order $order): array
    {
        $order = $order->fresh(['items.product']);
        $status = strtolower((string) $order->status);
        $backfilled = 0;
        $cleared = 0;

        if (in_array($status, ['cancelled', 'returned'], true)) {
            $cleared = $this->clearCostsWhenReturnStillShowsCogs($order);
        } else {
            $backfilled = $this->backfillMissingLineCostsFromProducts($order);
        }

        return [
            'changed' => ($backfilled + $cleared) > 0,
            'backfilled_lines' => $backfilled,
            'cleared_return_lines' => $cleared,
        ];
    }

    /**
     * @return int Lines updated
     */
    private function backfillMissingLineCostsFromProducts(Order $order): int
    {
        $updated = 0;

        foreach ($order->items as $item) {
            if (! $item->product_id || ! $item->product instanceof Product) {
                continue;
            }

            if ($item->effectiveUnitCost() >= 0.01) {
                continue;
            }

            $productCost = $this->productCosts->effectiveUnitCost($item->product);

            if ($productCost < 0.01) {
                continue;
            }

            $item->forceFill([
                'purchase_price' => round((float) $item->product->purchase_price, 2),
                'unit_cost' => $productCost,
            ])->save();

            $updated++;
        }

        return $updated;
    }

    /**
     * @return int Lines cleared
     */
    private function clearCostsWhenReturnStillShowsCogs(Order $order): int
    {
        if ($order->cogs() < 0.01) {
            return 0;
        }

        $updated = 0;

        foreach ($order->items as $item) {
            $kept = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));

            if ($kept < 1) {
                continue;
            }

            if ($item->effectiveUnitCost() < 0.01) {
                continue;
            }

            $item->forceFill([
                'purchase_price' => 0,
                'unit_cost' => 0,
            ])->save();

            $updated++;
        }

        return $updated;
    }
}
