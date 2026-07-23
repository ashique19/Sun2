<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;

class ResellerAccess
{
    public static function isReseller(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null
            && $user->hasRole('reseller')
            && (bool) $user->is_active;
    }

    public static function ensureReseller(?User $user = null): void
    {
        abort_unless(self::isReseller($user), 403);
    }

    public static function canViewOrder(Order $order, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if ($user === null || ! self::isReseller($user)) {
            return false;
        }

        return (int) $order->reseller_id === (int) $user->id;
    }

    public static function ensureCanViewOrder(Order $order, ?User $user = null): void
    {
        abort_unless(self::canViewOrder($order, $user), 403);
    }
}
