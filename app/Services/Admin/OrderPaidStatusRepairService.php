<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderPaymentSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Settle payment received for:
     * - legacy status "paid" → delivered + order total as COD receipt
     * - delivered orders with order value not yet on the payment ledger / collected
     *
     * COD fee on analytics follows collected_amount after OrderPaymentSync.
     *
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
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $fixed = 0;
        $paymentsCreated = 0;
        $samples = [];

        foreach ($orders as $order) {
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
    private function repairOne(Order $order): int
    {
        $order = $order->fresh(['paymentTransactions', 'courier']);
        $previousStatus = (string) $order->status;
        $wasLegacyPaid = strtolower($previousStatus) === 'paid';
        $created = 0;

        $targetReceived = $this->targetReceivedAmount($order);

        $alreadyPaid = round(
            (float) $order->paymentTransactions
                ->filter(fn (PaymentTransaction $tx) => $tx->isSuccessful())
                ->sum(fn (PaymentTransaction $tx) => (float) $tx->amount),
            2,
        );

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
                        'from' => round((float) $order->collected_amount, 2) >= 0.01
                            ? 'orders.collected_amount'
                            : 'orders.total',
                        'previous_status' => $previousStatus,
                        'target_received' => $targetReceived,
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
                'Legacy status “paid” converted to delivered; order value recorded as payment received.',
                auth()->id(),
                $extras,
            );
        } elseif ($created > 0) {
            $this->statuses->record(
                $order->fresh(),
                'Payment received backfilled for delivered order (received amount was unaccounted).',
                auth()->id(),
            );
        }

        return $created;
    }

    /**
     * Prefer an existing collected figure when present; otherwise the order total.
     */
    private function targetReceivedAmount(Order $order): float
    {
        $collected = round((float) ($order->collected_amount ?? 0), 2);

        if ($collected >= 0.01) {
            return $collected;
        }

        $total = round((float) $order->total, 2);

        if ($total >= 0.01) {
            return $total;
        }

        return round((float) $order->collectableAmount(), 2);
    }

    private function eligibleQuery(): Builder
    {
        return Order::query()->where(function (Builder $query): void {
            $query->whereRaw('LOWER(status) = ?', ['paid'])
                ->orWhere(function (Builder $delivered): void {
                    $delivered->where('status', 'delivered')
                        ->where('total', '>=', 0.01)
                        ->where(function (Builder $unsettled): void {
                            // Nothing on the ledger / collected yet.
                            $unsettled->where(function (Builder $zero): void {
                                $zero->where('collected_amount', '<', 0.01)
                                    ->where('paid_amount', '<', 0.01);
                            })->orWhere(function (Builder $ledgerGap): void {
                                // Collected set but paid_amount / ledger never synced.
                                $ledgerGap->where('collected_amount', '>=', 0.01)
                                    ->where('paid_amount', '<', 0.01);
                            });
                        });
                });
        });
    }
}
