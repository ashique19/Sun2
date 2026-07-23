<?php

namespace App\Services\Reseller;

use App\Models\User;
use App\Support\PhoneNumber;

class ResellerResolver
{
    /**
     * Resolve an active reseller by numeric user id or phone.
     */
    public function resolve(?string $idOrPhone): ?User
    {
        $raw = trim((string) $idOrPhone);

        if ($raw === '') {
            return null;
        }

        $base = User::query()
            ->role('reseller')
            ->where('is_active', true);

        if (ctype_digit($raw)) {
            $byId = (clone $base)->whereKey((int) $raw)->first();
            if ($byId) {
                return $byId;
            }
        }

        $normalized = PhoneNumber::normalize($raw);

        return (clone $base)->where('phone', $normalized)->first()
            ?? (clone $base)->where('phone', $raw)->first();
    }
}
