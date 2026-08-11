<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderPaymentSync;
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
     * Convert legacy order status "paid" → delivered, settle payment received = order total,
     * then refresh paid/due/COD scalars from the ledger (COD fee on analytics follows collected).
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
        $created = 0;
        $orderValue = round((float) $order->total, 2);

        if ($orderValue < 0.01) {
            $orderValue = round((float) $order->collectableAmount(), 2);
        }

        $alreadyPaid = round(
            (float) $order->paymentTransactions
                ->filter(fn (PaymentTransaction $tx) => $tx->isSuccessful())
                ->sum(fn (PaymentTransaction $tx) => (float) $tx->amount),
            2,
        );

        if ($orderValue >= 0.01 && $alreadyPaid + 0.009 < $orderValue) {
            $amount = round($orderValue - $alreadyPaid, 2);
            $externalId = 'legacy-paid-status-'.$order->id;

            $exists = PaymentTransaction::query()
                ->where('external_id', $externalId)
                ->exists();

            if (! $exists) {
                PaymentTransaction::query()->create([
                    'order_id' => $order->id,
                    'method' => 'cod',
                    'amount' => $amount,
                    'reference' => 'legacy-paid-status-repair',
                    'status' => 'completed',
                    'kind' => 'settlement',
                    'paid_at' => $order->actual_delivery_date
                        ?? $order->payment_date
                        ?? $order->placed_at
                        ?? $order->created_at
                        ?? now(),
                    'external_id' => $externalId,
                    'meta' => [
                        'source' => 'paid_status_repair',
                        'from' => 'orders.total',
                        'previous_status' => $order->status,
                    ],
                ]);
                $created = 1;
            }
        }

        $this->paymentSync->sync($order->fresh(['paymentTransactions']));

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

        return $created;
    }

    private function eligibleQuery()
    {
        return Order::query()->whereRaw('LOWER(status) = ?', ['paid']);
    }
}
