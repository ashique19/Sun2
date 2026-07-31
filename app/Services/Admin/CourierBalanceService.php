<?php

namespace App\Services\Admin;

use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\SteadfastApiClient;
use App\Services\Orders\OrderTotalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierBalanceService
{
    public function __construct(
        private readonly SteadfastApiClient $steadfast,
        private readonly CourierApiRegistry $courierRegistry,
        private readonly OrderTotalCalculator $totals,
    ) {}

    /**
     * Credit book balance when an order is dispatched (COD the courier will hold/owe).
     */
    public function creditOnDispatch(Courier $courier, Order $order, ?int $createdBy = null): void
    {
        $alreadyCredited = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->exists();

        if ($alreadyCredited) {
            return;
        }

        $amount = (int) round($order->collectableAmount());

        if ($amount <= 0) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'dispatch',
            amount: $amount,
            orderId: $order->id,
            note: 'Dispatch #'.$order->order_number,
            createdBy: $createdBy,
        );
    }

    /**
     * Reverse a prior dispatch credit (full cancel & return / no COD collected).
     */
    public function reverseDispatchCredit(Courier $courier, Order $order, ?int $createdBy = null): void
    {
        $dispatch = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->first();

        if (! $dispatch) {
            return;
        }

        $alreadyReversed = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'return')
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $amount = (int) $dispatch->amount;

        if ($amount === 0) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'return',
            amount: -$amount,
            orderId: $order->id,
            note: 'Reverse dispatch #'.$order->order_number.' (C/R)',
            createdBy: $createdBy,
        );
    }

    /**
     * After partial return: reverse original COD credit, then credit what was actually collected.
     */
    public function settleAfterPartialReturn(Courier $courier, Order $order, int $collectedAmount, ?int $createdBy = null): void
    {
        $this->reverseDispatchCredit($courier, $order, $createdBy);

        if ($collectedAmount <= 0) {
            return;
        }

        $alreadySettled = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->where('note', 'like', 'Partial collect%')
            ->exists();

        if ($alreadySettled) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'dispatch',
            amount: $collectedAmount,
            orderId: $order->id,
            note: 'Partial collect #'.$order->order_number,
            createdBy: $createdBy,
        );
    }

    /**
     * Record a withdrawal / remittance received from the courier.
     */
    public function withdraw(Courier $courier, int $amount, ?string $note = null, ?int $createdBy = null): Courier
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'withdrawAmount' => 'Enter an amount greater than zero.',
            ]);
        }

        $current = (int) round((float) $courier->balance);

        if ($amount > $current) {
            throw ValidationException::withMessages([
                'withdrawAmount' => 'Cannot withdraw more than the book balance (৳'.number_format($current, 0).').',
            ]);
        }

        return $this->apply(
            courier: $courier,
            type: 'withdraw',
            amount: -$amount,
            orderId: null,
            note: $note ?: 'Withdrawal / remittance received',
            createdBy: $createdBy,
        );
    }

    /**
     * Live wallet balance from the courier API, if available.
     *
     * Never throws — API/network failures return null so admin pages stay usable.
     */
    public function fetchApiBalance(Courier $courier): ?float
    {
        $slug = strtolower((string) $courier->slug);

        if ($slug === '' || ! $this->courierRegistry->isConfigured($slug)) {
            return null;
        }

        try {
            return match ($slug) {
                'steadfast' => $this->steadfast->getBalance(),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, float|null> courier_id => api balance
     */
    public function fetchApiBalancesFor(iterable $couriers): array
    {
        $balances = [];

        foreach ($couriers as $courier) {
            $balances[$courier->id] = $this->fetchApiBalance($courier);
        }

        return $balances;
    }

    /**
     * Admin-facing balance breakdown for a courier.
     *
     * - pending: COD still with courier on dispatched parcels (in process)
     * - receivable: net remittance due for delivered parcels after courier fees, minus withdrawals
     * - book: running ledger on couriers.balance
     *
     * Uses OrderTotalCalculator::codCharge() directly — never Order::codCharge()/moneyTotals(),
     * which would eager-load items + adjustments for every delivered order.
     *
     * @return array{pending: float, receivable: float, book: float, withdrawn: float}
     */
    public function summarize(Courier $courier): array
    {
        return $this->summarizeMany([$courier])[$courier->id];
    }

    /**
     * @param  iterable<Courier>  $couriers
     * @return array<int, array{pending: float, receivable: float, book: float, withdrawn: float}>
     */
    public function summarizeMany(iterable $couriers): array
    {
        /** @var Collection<int, Courier> $courierList */
        $courierList = collect($couriers)->keyBy('id');
        $ids = $courierList->keys()->all();

        if ($ids === []) {
            return [];
        }

        $pendingByCourier = Order::query()
            ->whereIn('courier_id', $ids)
            ->where('status', 'dispatched')
            ->selectRaw('courier_id, COALESCE(SUM(
                CASE
                    WHEN COALESCE(cod_amount, 0) > 0 THEN cod_amount
                    WHEN COALESCE(due_amount, 0) > 0 THEN due_amount
                    ELSE COALESCE(total, 0)
                END
            ), 0) as pending')
            ->groupBy('courier_id')
            ->pluck('pending', 'courier_id');

        $withdrawnByCourier = CourierBalanceEntry::query()
            ->whereIn('courier_id', $ids)
            ->where('type', 'withdraw')
            ->selectRaw('courier_id, ABS(COALESCE(SUM(amount), 0)) as withdrawn')
            ->groupBy('courier_id')
            ->pluck('withdrawn', 'courier_id');

        $grossByCourier = array_fill_keys($ids, 0.0);

        Order::query()
            ->whereIn('courier_id', $ids)
            ->where('status', 'delivered')
            ->select(['id', 'courier_id', 'collected_amount', 'delivery_charge', 'courier_charge'])
            ->orderBy('id')
            ->chunkById(1000, function ($orders) use (&$grossByCourier, $courierList): void {
                foreach ($orders as $order) {
                    $courier = $courierList->get($order->courier_id);

                    if (! $courier) {
                        continue;
                    }

                    $collected = max(0.0, (float) ($order->collected_amount ?? 0));
                    $courierCharge = max(0.0, (float) ($order->courier_charge ?? 0));
                    $codCharge = $this->totals->codCharge(
                        collectedAmount: $collected,
                        deliveryCharge: (float) $order->delivery_charge,
                        courierSlug: $courier->slug,
                        codPercentage: (float) ($courier->cod_percentage ?? 1),
                    );

                    $grossByCourier[$order->courier_id] += max(0.0, $collected - $courierCharge - $codCharge);
                }
            });

        $summaries = [];

        foreach ($courierList as $id => $courier) {
            $pending = (float) ($pendingByCourier[$id] ?? 0);
            $withdrawn = (float) ($withdrawnByCourier[$id] ?? 0);
            $grossRemittable = (float) ($grossByCourier[$id] ?? 0);

            $summaries[$id] = [
                'pending' => round($pending, 2),
                'receivable' => round(max(0.0, $grossRemittable - $withdrawn), 2),
                'book' => round((float) $courier->balance, 2),
                'withdrawn' => round($withdrawn, 2),
            ];
        }

        return $summaries;
    }

    private function apply(
        Courier $courier,
        string $type,
        int $amount,
        ?int $orderId,
        ?string $note,
        ?int $createdBy,
    ): Courier {
        return DB::transaction(function () use ($courier, $type, $amount, $orderId, $note, $createdBy) {
            $locked = Courier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $newBalance = (int) round((float) $locked->balance) + $amount;

            $locked->update(['balance' => $newBalance]);

            CourierBalanceEntry::query()->create([
                'courier_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'order_id' => $orderId,
                'note' => $note,
                'created_by' => $createdBy ?? auth()->id(),
            ]);

            return $locked->fresh();
        });
    }
}
