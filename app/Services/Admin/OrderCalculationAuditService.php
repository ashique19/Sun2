<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderEmptyProductDefaults;
use App\Services\Orders\OrderPackagingCost;
use Illuminate\Support\Collection;

class OrderCalculationAuditService
{
    public const BATCH_SIZE = 100;

    public const MAX_STORED_ISSUES = 300;

    public function __construct(
        private OrderCostSnapshotRepairService $costSnapshots,
        private OrderPackagingCourierRepairService $packagingCourier,
        private OrderPaidStatusRepairService $paidStatus,
        private OrderPackagingCost $packaging,
        private OrderCourierChargeSync $courierCharges,
        private ProductUnitCostService $productCosts,
    ) {}

    public function eligibleOrderCount(): int
    {
        return (int) Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->count();
    }

    /**
     * Scan the next batch: auto-fix when safe, otherwise record manual issues.
     *
     * @return array{
     *     scanned: int,
     *     auto_fixed: int,
     *     manual_needed: int,
     *     next_after_id: int,
     *     done: bool,
     *     sample_auto_fixes: list<string>,
     *     issues: list<array{order_id: int, order_number: string, url: string, issues: list<string>}>
     * }
     */
    public function auditNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with([
                'items.product.category',
                'courier:id,name,slug,charge,osd_charge,cod_percentage',
                'paymentTransactions',
            ])
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $autoFixed = 0;
        $manualNeeded = 0;
        $sampleAutoFixes = [];
        $issues = [];

        foreach ($orders as $order) {
            $result = $this->auditOrder($order);

            if ($result['auto_fixed']) {
                $autoFixed++;
                if (count($sampleAutoFixes) < 8) {
                    $sampleAutoFixes[] = (string) $order->order_number;
                }
            }

            if ($result['issues'] !== []) {
                $manualNeeded++;
                $issues[] = [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'url' => route('admin.orders.show', $order),
                    'issues' => $result['issues'],
                ];
            }
        }

        $lastId = $orders->last()?->id ?? $afterId;

        return [
            'scanned' => $orders->count(),
            'auto_fixed' => $autoFixed,
            'manual_needed' => $manualNeeded,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_auto_fixes' => $sampleAutoFixes,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{auto_fixed: bool, issues: list<string>}
     */
    public function auditOrder(Order $order): array
    {
        $fixes = [];

        if ($this->paidStatus->needsRepair($order)) {
            $this->paidStatus->repairOrder($order);
            $fixes[] = 'payment';
        }

        $logistics = $this->packagingCourier->repairOrder($order);
        if ($logistics['changed']) {
            $fixes[] = 'packaging_courier';
        }

        // Order lines already have COGS but catalog products may still be ৳0 —
        // reverse-fill products first so later line backfill / audits stay consistent.
        if ($this->productCosts->backfillMissingProductsFromOrder($order) > 0) {
            $fixes[] = 'product_cost_from_orders';
        }

        $cogs = $this->costSnapshots->repairOrder($order->fresh(['items.product']));
        if ($cogs['changed']) {
            $fixes[] = 'cogs';
        }

        $order = $order->fresh([
            'items.product.category',
            'courier:id,name,slug,charge,osd_charge,cod_percentage',
            'paymentTransactions',
        ]);

        return [
            'auto_fixed' => $fixes !== [],
            'issues' => $this->collectManualIssues($order),
        ];
    }

    /**
     * @return list<string>
     */
    private function collectManualIssues(Order $order): array
    {
        $issues = [];
        $status = strtolower((string) $order->status);

        if ($this->paidStatus->needsRepair($order)) {
            $issues[] = 'Payment received still missing after auto-fix (status '.$order->status
                .', total ৳'.$this->money((float) $order->total)
                .', collected ৳'.$this->money((float) ($order->collected_amount ?? 0))
                .', paid ৳'.$this->money((float) ($order->paid_amount ?? 0)).').';
        }

        $expectedPackaging = round($this->packaging->estimateFor($order), 2);
        $actualPackaging = round((float) ($order->packaging_cost ?? 0), 2);

        if ($expectedPackaging >= 0.01 && $actualPackaging < 0.01) {
            $issues[] = 'Packaging is ৳0; rate card expects ৳'.$this->money($expectedPackaging).'.';
        } elseif ($actualPackaging >= 0.01 && $expectedPackaging >= 0.01
            && abs($actualPackaging - $expectedPackaging) >= 0.01) {
            $issues[] = 'Packaging ৳'.$this->money($actualPackaging)
                .' differs from rate card ৳'.$this->money($expectedPackaging).'.';
        }

        $expectedCourier = round(
            $this->courierCharges->estimateMerchantDeliveryFee($order, $order->courier),
            2,
        );
        $actualCourier = round((float) ($order->courier_charge ?? 0), 2);

        if ($order->courier_id && $expectedCourier >= 0.01 && $actualCourier < 0.01) {
            $issues[] = 'Courier charge is ৳0; estimate expects ৳'.$this->money($expectedCourier).'.';
        } elseif ($order->courier_id && $actualCourier >= 0.01 && $expectedCourier >= 0.01
            && abs($actualCourier - $expectedCourier) >= 0.01) {
            $issues[] = 'Courier ৳'.$this->money($actualCourier)
                .' differs from estimate ৳'.$this->money($expectedCourier).'.';
        }

        if (in_array($status, ['cancelled', 'returned'], true)) {
            if ($order->cogs() >= 0.01) {
                $issues[] = 'Cancelled/returned order still shows COGS ৳'.$this->money($order->cogs())
                    .' (should be ৳0 for contribution).';
            }
        } elseif ($this->costSnapshots->hasNoProductQuantity($order)) {
            if (abs($order->cogs() - OrderEmptyProductDefaults::COGS) >= 0.01) {
                $issues[] = 'Order has no products; COGS is ৳'.$this->money($order->cogs())
                    .' but should be ৳'.$this->money(OrderEmptyProductDefaults::COGS).'.';
            }
        } else {
            foreach ($order->items as $item) {
                if ((string) $item->name === OrderEmptyProductDefaults::COGS_LINE_NAME) {
                    continue;
                }

                if ($item->effectiveUnitCost() >= 0.01) {
                    continue;
                }

                $kept = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));
                if ($kept < 1) {
                    continue;
                }

                $label = trim((string) ($item->name ?: 'Line #'.$item->id));

                if (! $item->product_id || ! $item->product instanceof Product) {
                    $issues[] = 'Line “'.$label.'” has ৳0 COGS and no linked product cost to backfill.';

                    continue;
                }

                $productCost = $this->productCosts->effectiveUnitCost($item->product);

                if ($productCost < 0.01) {
                    $issues[] = 'Line “'.$label.'” has ৳0 COGS and product “'.$item->product->name
                        .'” has no unit cost set.';
                } else {
                    $issues[] = 'Line “'.$label.'” still has ৳0 COGS though product cost is ৳'
                        .$this->money($productCost).'.';
                }
            }
        }

        return $issues;
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
