<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;

class AdminAccess
{
    public static function isStaffAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->hasAnyRole(['admin', 'dev']);
    }

    public static function isModeratorOnly(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null
            && $user->hasRole('moderator')
            && ! $user->hasAnyRole(['admin', 'dev']);
    }

    public static function canAccessAdmin(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && $user->hasAnyRole(['admin', 'dev', 'moderator']);
    }

    public static function canViewNewOrder(Order $order, ?User $user = null): bool
    {
        if (self::isStaffAdmin($user)) {
            return true;
        }

        if (! self::isModeratorOnly($user)) {
            return false;
        }

        return in_array($order->status, ['new', 'confirmed'], true);
    }

    public static function ensureCanManageOrders(): void
    {
        abort_unless(self::isStaffAdmin(), 403);
    }

    public static function ensureCanViewOrder(Order $order): void
    {
        abort_unless(self::canViewNewOrder($order), 403);
    }
}
