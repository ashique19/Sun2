<?php

namespace App\Services\Reseller;

use App\Models\ResellerWalletEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResellerWalletService
{
    /**
     * Append a signed ledger entry and update cached balance.
     */
    public function append(
        int $userId,
        float $amount,
        string $type,
        ?int $orderId = null,
        ?string $note = null,
        ?int $createdBy = null,
    ): ResellerWalletEntry {
        return DB::transaction(function () use ($userId, $amount, $type, $orderId, $note, $createdBy) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $balance = round((float) $user->reseller_balance + $amount, 2);
            $user->reseller_balance = $balance;
            $user->save();

            return ResellerWalletEntry::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'order_id' => $orderId,
                'note' => $note,
                'created_by' => $createdBy ?? auth()->id(),
            ]);
        });
    }

    /**
     * Record an admin payout (debit wallet).
     */
    public function recordPayout(int $userId, float $amount, ?string $note = null, ?int $createdBy = null): ResellerWalletEntry
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('Payout amount must be greater than zero.');
        }

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        if ((float) $user->reseller_balance < $amount) {
            throw new RuntimeException('Payout exceeds available reseller balance.');
        }

        return $this->append(
            userId: $userId,
            amount: -1 * $amount,
            type: 'payout',
            orderId: null,
            note: $note ?: 'Payout recorded by admin.',
            createdBy: $createdBy,
        );
    }
}
