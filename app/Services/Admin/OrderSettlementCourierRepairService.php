<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderCourierChargeSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Batch repair for production (modal, 100/order pass):
 * 1. Fill ৳0 courier_charge on delivered/returned using piece-based rate card
 * 2. Settle delivered non-exchange bills onto the payment ledger (mark fully paid)
 */
class OrderSettlementCourierRepairService
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private OrderCourierChargeSync $courierCharges,
        private OrderPaidStatusRepairService $settlement,
    ) {}

    public function eligibleOrderCount(): int
    {
        return (int) $this->eligibleQuery()->count();
    }

    /**
     * @return array{
     *     scanned: int,
     *     fixed_orders: int,
     *     courier_fixed: int,
     *     settlement_fixed: int,
     *     payments_created: int,
     *     next_after_id: int,
     *     done: bool,
     *     sample_order_numbers: list<string>
     * }
     */
    public function repairNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));

        /** @var Collection<int, Order> $orders */
        $orders = $this->eligibleQuery()
            ->with([
                'items',
                'courier:id,name,slug,charge,osd_charge,cod_percentage',
                'paymentTransactions',
            ])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $fixedOrders = 0;
        $courierFixed = 0;
        $settlementFixed = 0;
        $paymentsCreated = 0;
        $samples = [];

        foreach ($orders as $order) {
            $result = $this->repairOrder($order);

            if (! $result['changed']) {
                continue;
            }

            $fixedOrders++;
            $courierFixed += $result['courier_fixed'] ? 1 : 0;
            $settlementFixed += $result['settlement_fixed'] ? 1 : 0;
            $paymentsCreated += $result['payments_created'];

            if (count($samples) < 8) {
                $samples[] = (string) $order->order_number;
            }
        }

        $lastId = $orders->last()?->id ?? $afterId;

        return [
            'scanned' => $orders->count(),
            'fixed_orders' => $fixedOrders,
            'courier_fixed' => $courierFixed,
            'settlement_fixed' => $settlementFixed,
            'payments_created' => $paymentsCreated,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_order_numbers' => $samples,
        ];
    }

    /**
     * @return array{
     *     changed: bool,
     *     courier_fixed: bool,
     *     settlement_fixed: bool,
     *     payments_created: int
     * }
     */
    public function repairOrder(Order $order): array
    {
        $order = $order->fresh(['items', 'courier', 'paymentTransactions']);
        $courierFixed = false;
        $paymentsCreated = 0;
        $settlementFixed = false;

        if ($this->needsCourierRepair($order)) {
            $fee = $this->courierCharges->estimateMerchantDeliveryFee($order, $order->courier);

            if ($fee >= 0.01) {
                $this->courierCharges->set(
                    $order->fresh(),
                    $fee,
                    'manual',
                    null,
                    [
                        'source' => 'settlement_courier_repair',
                        'rule' => 'piece_based_rate_card',
                    ],
                );
                $courierFixed = true;
                $order = $order->fresh(['items', 'courier', 'paymentTransactions']);
            }
        }

        if ($this->settlement->needsRepair($order)) {
            $beforeDue = round((float) ($order->due_amount ?? 0), 2);
            $beforeStatus = strtolower((string) ($order->payment_status ?? ''));
            $created = $this->settlement->repairOrder($order);
            $order = $order->fresh();
            $paymentsCreated = $created;
            $settlementFixed = $created > 0
                || round((float) ($order->due_amount ?? 0), 2) < 0.01
                || strtolower((string) ($order->payment_status ?? '')) === 'paid'
                || $beforeDue !== round((float) ($order->due_amount ?? 0), 2)
                || $beforeStatus !== strtolower((string) ($order->payment_status ?? ''));
        }

        return [
            'changed' => $courierFixed || $settlementFixed,
            'courier_fixed' => $courierFixed,
            'settlement_fixed' => $settlementFixed,
            'payments_created' => $paymentsCreated,
        ];
    }

    public function needsCourierRepair(Order $order): bool
    {
        $status = strtolower((string) $order->status);

        if (! in_array($status, ['delivered', 'returned'], true)) {
            return false;
        }

        if (round((float) ($order->courier_charge ?? 0), 2) >= 0.01) {
            return false;
        }

        if (! $order->courier_id) {
            return false;
        }

        return true;
    }

    private function eligibleQuery(): Builder
    {
        return Order::query()->where(function (Builder $query): void {
            $query->where(function (Builder $courier): void {
                $courier->whereIn('status', ['delivered', 'returned'])
                    ->whereNotNull('courier_id')
                    ->where('courier_charge', '<', 0.01);
            })->orWhere(function (Builder $settle): void {
                // Mirror OrderPaidStatusRepairService eligibility (legacy paid + delivered gaps).
                $settle->whereRaw('LOWER(status) = ?', ['paid'])
                    ->orWhere(function (Builder $delivered): void {
                        $delivered->where('status', 'delivered')
                            ->where(function (Builder $notExchange): void {
                                $notExchange->where('is_replacement', false)
                                    ->orWhereNull('is_replacement');
                            })
                            ->where('total', '>=', 0.01)
                            ->where(function (Builder $gap): void {
                                $gap->where('due_amount', '>=', 0.01)
                                    ->orWhere('payment_status', '!=', 'paid')
                                    ->orWhereColumn('collected_amount', '<', 'total')
                                    ->orWhereColumn('paid_amount', '<', 'total')
                                    ->orWhereDoesntHave('paymentTransactions', function (Builder $tx): void {
                                        $tx->whereIn('status', PaymentTransaction::SUCCESSFUL_STATUSES);
                                    });
                            });
                    });
            });
        });
    }
}
