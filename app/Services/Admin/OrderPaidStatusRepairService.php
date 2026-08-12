<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderPaymentSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settle delivered non-exchange orders to the customer bill ({@see Order::$total}).
 *
 * Analytics revenue stays {@see Order::$collected_amount} (COD ledger sum). For a normal
 * delivered parcel that is: product (subtotal) + customer delivery + other charges − discount.
 *
 * Courier charge is a cost after collection — it is not deducted from paid/collected.
 */
class OrderPaidStatusRepairService
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private OrderPaymentSync $paymentSync,
        private OrderStatusService $statuses,
    ) {}

    public function eligibleOrderCount(): int
    {
        return (int) $this->eligibleQuery()->count();
    }

    /**
     * @return array{
     *     scanned: int,
     *     fixed_orders: int,
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
            ->with(['paymentTransactions', 'courier'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $fixed = 0;
        $paymentsCreated = 0;
        $samples = [];

        foreach ($orders as $order) {
            if (! $this->needsRepair($order)) {
                continue;
            }

            $created = DB::transaction(function () use ($order): int {
                return $this->repairOne($order);
            });

            $fixed++;
            $paymentsCreated += $created;

            if (count($samples) < 8) {
                $samples[] = (string) $order->order_number;
            }
        }

        $lastId = $orders->last()?->id ?? $afterId;

        return [
            'scanned' => $orders->count(),
            'fixed_orders' => $fixed,
            'payments_created' => $paymentsCreated,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_order_numbers' => $samples,
        ];
    }

    /**
     * @return int Number of payment transactions created (0 or 1)
     */
    public function repairOrder(Order $order): int
    {
        return DB::transaction(fn (): int => $this->repairOne($order));
    }

    /**
     * @return int Number of payment transactions created (0 or 1)
     */
    private function repairOne(Order $order): int
    {
        $order = $order->fresh(['paymentTransactions', 'courier']);
        $previousStatus = (string) $order->status;
        $wasLegacyPaid = strtolower($previousStatus) === 'paid';
        $created = 0;

        $targetReceived = $this->targetReceivedAmount($order);
        $alreadyPaid = $this->successfulLedgerTotal($order);

        if ($targetReceived >= 0.01 && $alreadyPaid + 0.009 < $targetReceived) {
            $amount = round($targetReceived - $alreadyPaid, 2);
            $externalId = 'payment-received-repair-'.$order->id;

            $exists = PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->whereIn('external_id', [
                    $externalId,
                    'legacy-paid-status-'.$order->id,
                    'legacy-collected-'.$order->id,
                ])
                ->exists();

            if (! $exists) {
                PaymentTransaction::query()->create([
                    'order_id' => $order->id,
                    'method' => 'cod',
                    'amount' => $amount,
                    'reference' => 'payment-received-repair',
                    'status' => 'completed',
                    'kind' => 'settlement',
                    'paid_at' => $order->actual_delivery_date
                        ?? $order->payment_date
                        ?? $order->placed_at
                        ?? $order->created_at
                        ?? now(),
                    'external_id' => $externalId,
                    'meta' => [
                        'source' => 'payment_received_repair',
                        'from' => 'orders.total',
                        'previous_status' => $previousStatus,
                        'target_received' => $targetReceived,
                        'ledger_before' => $alreadyPaid,
                        'is_replacement' => (bool) $order->is_replacement,
                    ],
                ]);
                $created = 1;
            }
        }

        $this->paymentSync->sync($order->fresh(['paymentTransactions']));

        if ($wasLegacyPaid) {
            $extras = [];
            if ($order->actual_delivery_date === null) {
                $extras['actual_delivery_date'] = $order->payment_date
                    ?? $order->placed_at
                    ?? now();
            }

            $this->statuses->update(
                $order->fresh(),
                'delivered',
                'Legacy status “paid” converted to delivered; bill total recorded as payment received.',
                auth()->id(),
                $extras,
            );
        } elseif ($created > 0) {
            $this->statuses->record(
                $order->fresh(),
                'Delivered settlement backfilled to bill total (payment ledger / due amount).',
                auth()->id(),
            );
        }

        return $created;
    }

    public function needsRepair(Order $order): bool
    {
        $status = strtolower((string) $order->status);

        if ($status === 'paid') {
            return true;
        }

        if ($status !== 'delivered') {
            return false;
        }

        if ((bool) $order->is_replacement) {
            return false;
        }

        $total = round((float) $order->total, 2);

        if ($total < 0.01) {
            return false;
        }

        $ledgerPaid = $this->successfulLedgerTotal($order);

        if ($ledgerPaid + 0.009 < $total) {
            return true;
        }

        $due = round((float) ($order->due_amount ?? 0), 2);
        $paymentStatus = strtolower((string) ($order->payment_status ?? ''));
        $collected = round((float) ($order->collected_amount ?? 0), 2);
        $paid = round((float) ($order->paid_amount ?? 0), 2);

        // Scalars/ledger out of sync (common after migration set collected=paid=total with no txns).
        if ($due >= 0.01 || $paymentStatus !== 'paid') {
            return true;
        }

        if (abs($collected - $total) >= 0.01 || abs($paid - $total) >= 0.01) {
            return true;
        }

        return false;
    }

    /**
     * Full customer bill for delivered non-exchange (and legacy paid → delivered).
     */
    private function targetReceivedAmount(Order $order): float
    {
        $total = round((float) $order->total, 2);

        if ($total >= 0.01) {
            return $total;
        }

        return round((float) $order->collectableAmount(), 2);
    }

    private function successfulLedgerTotal(Order $order): float
    {
        $order->loadMissing('paymentTransactions');

        return round(
            (float) $order->paymentTransactions
                ->filter(fn (PaymentTransaction $tx) => $tx->isSuccessful())
                ->sum(fn (PaymentTransaction $tx) => (float) $tx->amount),
            2,
        );
    }

    private function eligibleQuery(): Builder
    {
        return Order::query()->where(function (Builder $query): void {
            $query->whereRaw('LOWER(status) = ?', ['paid'])
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
                                ->orWhere('collected_amount', '<', DB::raw('orders.total - 0.009'))
                                ->orWhere('paid_amount', '<', DB::raw('orders.total - 0.009'))
                                ->orWhereDoesntHave('paymentTransactions', function (Builder $tx): void {
                                    $tx->whereIn('status', PaymentTransaction::SUCCESSFUL_STATUSES);
                                });
                        });
                });
        });
    }
}
