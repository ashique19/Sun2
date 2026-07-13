<?php

namespace App\Services\Admin;

use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\Order;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\SteadfastApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CourierBalanceService
{
    public function __construct(
        private readonly SteadfastApiClient $steadfast,
        private readonly CourierApiRegistry $courierRegistry,
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
        } catch (RuntimeException) {
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
