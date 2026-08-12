<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\OrderEmptyProductDefaults;
use App\Services\Orders\OrderPackagingCost;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Report-only scan: verify each order's analytics columns and catch data that
 * can crash year-scoped analytics pages (bad dates, moneyTotals failures, etc.).
 */
class OrderCalculationAuditService
{
    public const BATCH_SIZE = 100;

    public const MAX_STORED_ISSUES = 300;

    public function __construct(
        private AnalyticsService $analytics,
        private OrderCostSnapshotRepairService $costSnapshots,
        private ProductUnitCostService $productCosts,
        private OrderPackagingCost $packaging,
    ) {}

    /**
     * Years that appear on non-draft orders (placement or delivery), newest first.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = [];

        foreach (['placed_at', 'actual_delivery_date'] as $column) {
            DB::table('orders')
                ->where('status', '!=', Order::STATUS_DRAFT)
                ->whereNotNull($column)
                ->orderBy('id')
                ->select(['id', $column])
                ->chunkById(1000, function ($rows) use ($column, &$years): void {
                    foreach ($rows as $row) {
                        $year = $this->safeDhakaYear($row->{$column} ?? null);

                        if ($year !== null) {
                            $years[$year] = true;
                        }
                    }
                });
        }

        $list = array_keys($years);
        rsort($list, SORT_NUMERIC);

        $current = (int) now('Asia/Dhaka')->year;

        if ($list === []) {
            return [$current];
        }

        if (! in_array($current, $list, true)) {
            array_unshift($list, $current);
        }

        return $list;
    }

    public function eligibleOrderCount(?int $year = null): int
    {
        return (int) $this->baseQuery($year)->count();
    }

    /**
     * Scan the next batch and record column / crash issues (no auto-mutations).
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
    public function auditNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE, ?int $year = null): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));

        /** @var Collection<int, Order> $orders */
        $orders = $this->baseQuery($year)
            ->with([
                'items.product.category',
                'courier:id,name,slug,charge,osd_charge,cod_percentage',
                'adjustments:id,order_id,type,label,amount',
                'paymentTransactions',
            ])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $manualNeeded = 0;
        $issues = [];

        foreach ($orders as $order) {
            $result = $this->auditOrder($order);

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
            'auto_fixed' => 0,
            'manual_needed' => $manualNeeded,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_auto_fixes' => [],
            'issues' => $issues,
        ];
    }

    /**
     * @return array{auto_fixed: bool, issues: list<string>}
     */
    public function auditOrder(Order $order): array
    {
        return [
            'auto_fixed' => false,
            'issues' => $this->collectIssues($order),
        ];
    }

    /**
     * @return list<string>
     */
    private function collectIssues(Order $order): array
    {
        $issues = [];
        $status = strtolower((string) $order->status);

        foreach (['placed_at', 'actual_delivery_date', 'dispatch_date'] as $field) {
            $raw = $order->getAttributes()[$field] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            if ($this->safeDhakaYear($raw) === null) {
                $issues[] = 'Unreadable '.$field.' “'.(string) $raw
                    .'” (can crash year analytics when that year is opened).';
            }
        }

        $contrib = null;

        try {
            $contrib = $this->analytics->orderContribution($order);
        } catch (Throwable $e) {
            $issues[] = 'Contribution columns failed to calculate: '.$this->shortException($e);

            return $issues;
        }

        try {
            $order->moneyTotals();
        } catch (Throwable $e) {
            $issues[] = 'moneyTotals() failed (P&L / investor pages use this): '.$this->shortException($e);
        }

        $partsSum = round(
            $contrib['cogs'] + $contrib['packaging'] + $contrib['courier'] + $contrib['cod'],
            2,
        );

        if (abs($partsSum - $contrib['direct']) >= 0.01) {
            $issues[] = 'Direct ৳'.$this->money($contrib['direct'])
                .' ≠ COGS+packaging+courier+COD ৳'.$this->money($partsSum)
                .' (Revenue ৳'.$this->money($contrib['revenue'])
                .', COGS ৳'.$this->money($contrib['cogs'])
                .', packaging ৳'.$this->money($contrib['packaging'])
                .', courier ৳'.$this->money($contrib['courier'])
                .', COD ৳'.$this->money($contrib['cod']).').';
        }

        $expectedProfit = round($contrib['revenue'] - $contrib['direct'], 2);

        if (abs($expectedProfit - $contrib['profit']) >= 0.01) {
            $issues[] = 'P/L ৳'.$this->money($contrib['profit'])
                .' ≠ Revenue−Direct ৳'.$this->money($expectedProfit).'.';
        }

        if (in_array($status, ['cancelled', 'returned'], true)) {
            if ($contrib['cogs'] >= 0.01) {
                $issues[] = 'Cancelled/returned order still shows COGS ৳'.$this->money($contrib['cogs'])
                    .' (should be ৳0 for contribution).';
            }
        } elseif ($this->costSnapshots->hasNoProductQuantity($order)) {
            if (abs($contrib['cogs'] - OrderEmptyProductDefaults::COGS) >= 0.01) {
                $issues[] = 'Order has no products; COGS is ৳'.$this->money($contrib['cogs'])
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
                    $issues[] = 'Line “'.$label.'” has ৳0 COGS (no linked product cost).';

                    continue;
                }

                $productCost = $this->productCosts->effectiveUnitCost($item->product);

                if ($productCost < 0.01) {
                    $issues[] = 'Line “'.$label.'” has ৳0 COGS and product “'.$item->product->name
                        .'” has no unit cost.';
                } else {
                    $issues[] = 'Line “'.$label.'” still has ৳0 COGS though product cost is ৳'
                        .$this->money($productCost).'.';
                }
            }
        }

        $expectedPackaging = round($this->packaging->estimateFor($order), 2);

        if ($expectedPackaging >= 0.01 && $contrib['packaging'] < 0.01) {
            $issues[] = 'Packaging is ৳0; rate card expects ৳'.$this->money($expectedPackaging).'.';
        }

        if ($status === 'delivered'
            && ! (bool) $order->is_replacement
            && $contrib['revenue'] < 0.01
            && (float) ($order->total ?? 0) >= 0.01) {
            $issues[] = 'Delivered order has Revenue ৳0 but bill total ৳'
                .$this->money((float) $order->total)
                .' (collected_amount missing — analytics revenue will understate this year).';
        }

        if ($status === 'delivered' && ! (bool) $order->is_replacement) {
            $total = round((float) ($order->total ?? 0), 2);
            $due = round((float) ($order->due_amount ?? 0), 2);
            $collected = round((float) ($order->collected_amount ?? 0), 2);
            $paid = round((float) ($order->paid_amount ?? 0), 2);
            $paymentStatus = strtolower((string) ($order->payment_status ?? ''));

            $notFullyPaid = $due >= 0.01
                || ($paymentStatus !== 'paid'
                    && ($collected + 0.009 < $total || $paid + 0.009 < $total));

            if ($total >= 0.01 && $notFullyPaid) {
                $issues[] = 'Delivered non-exchange bill ৳'.$this->money($total)
                    .' is not fully marked paid (due ৳'.$this->money($due)
                    .', collected ৳'.$this->money($collected)
                    .', payment_status '.$paymentStatus
                    .'). Run orders:repair-delivered-settlement.';
            }
        }

        return $issues;
    }

    private function baseQuery(?int $year = null)
    {
        $query = Order::query()->where('status', '!=', Order::STATUS_DRAFT);

        if ($year === null) {
            return $query;
        }

        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->startOfDay();
        $end = $start->copy()->endOfYear();

        return $query->where(function ($inner) use ($start, $end): void {
            $inner->whereBetween('placed_at', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ])->orWhereBetween('actual_delivery_date', [
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function safeDhakaYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = $value instanceof Carbon
            ? $value->format('Y-m-d H:i:s')
            : trim((string) $value);

        if ($string === '' || str_starts_with($string, '0000-00-00')) {
            return null;
        }

        try {
            $carbon = $value instanceof Carbon
                ? $value->copy()
                : Carbon::parse($string);

            return (int) $carbon->timezone('Asia/Dhaka')->year;
        } catch (Throwable) {
            return null;
        }
    }

    private function shortException(Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return class_basename($e);
        }

        return mb_substr($message, 0, 160);
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
