<?php

namespace App\Services\Admin;

use App\Models\InvestorPitchShare;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvestorPitchShareService
{
    /**
     * @return array{share: InvestorPitchShare, plain_password: string, url: string}
     */
    public function create(?string $label, string $password, int $days, ?int $createdBy = null): array
    {
        $days = max(1, min(90, $days));
        $password = trim($password);

        if ($password === '') {
            throw ValidationException::withMessages([
                'sharePassword' => 'Enter a password for the recipient.',
            ]);
        }

        if (strlen($password) < 6) {
            throw ValidationException::withMessages([
                'sharePassword' => 'Use at least 6 characters for the share password.',
            ]);
        }

        $label = trim((string) $label);
        $label = $label !== '' ? $label : null;

        $share = InvestorPitchShare::query()->create([
            'token' => Str::random(48),
            'label' => $label,
            'password' => $password,
            'expires_at' => now()->addDays($days),
            'created_by' => $createdBy,
        ]);

        return [
            'share' => $share,
            'plain_password' => $password,
            'url' => $share->url(),
        ];
    }
}
