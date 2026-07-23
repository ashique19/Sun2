<?php

namespace App\Services\Reseller;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\ResellerWalletEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResellerCommissionService
{
    /**
     * Credit reseller wallet for a delivered order (idempotent).
     */
    public function creditOnDelivered(Order $order): void
    {
        if (! $order->reseller_id) {
            return;
        }

        $already = ResellerWalletEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'commission')
            ->exists();

        if ($already) {
            return;
        }

        $order->loadMissing('items');

        DB::transaction(function () use ($order) {
            $total = 0.0;

            foreach ($order->items as $item) {
                $earned = $this->lineCommission($item);
                $item->update(['commission_earned' => $earned]);
                $total += $earned;
            }

            $total = round($total, 2);
            if ($total <= 0) {
                return;
            }

            $this->credit(
                userId: (int) $order->reseller_id,
                amount: $total,
                type: 'commission',
                orderId: (int) $order->id,
                note: 'Commission for delivered order #'.$order->order_number,
            );
        });
    }

    /**
     * Reverse previously credited commission after a return/cancel-after-deliver.
     */
    public function reverseForOrder(Order $order, string $note = 'Commission reversed'): void
    {
        if (! $order->reseller_id) {
            return;
        }

        $prior = ResellerWalletEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'commission')
            ->sum('amount');

        $alreadyReversed = (float) ResellerWalletEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'reversal')
            ->sum('amount');

        $net = round((float) $prior + $alreadyReversed, 2);
        if ($net <= 0) {
            return;
        }

        $this->credit(
            userId: (int) $order->reseller_id,
            amount: -1 * $net,
            type: 'reversal',
            orderId: (int) $order->id,
            note: $note.' #'.$order->order_number,
        );

        foreach ($order->items as $item) {
            $item->update(['commission_earned' => 0]);
        }
    }

    public function lineCommission(OrderProduct $item): float
    {
        $qty = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));
        $base = (float) ($item->base_price ?: $item->price);
        $sell = (float) $item->price;
        $rate = (float) $item->commission_rate;
        $markup = max(0, $sell - $base);

        return round(($rate + $markup) * $qty, 2);
    }

    private function credit(int $userId, float $amount, string $type, ?int $orderId, string $note): void
    {
        DB::transaction(function () use ($userId, $amount, $type, $orderId, $note) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $balance = round((float) $user->reseller_balance + $amount, 2);
            $user->reseller_balance = $balance;
            $user->save();

            ResellerWalletEntry::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'order_id' => $orderId,
                'note' => $note,
                'created_by' => auth()->id(),
            ]);
        });
    }
}
