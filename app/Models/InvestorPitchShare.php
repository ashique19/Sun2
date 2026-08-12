<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class InvestorPitchShare extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAccessible(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function passwordMatches(string $password): bool
    {
        $hash = (string) ($this->getAttributes()['password'] ?? '');

        return $hash !== '' && Hash::check($password, $hash);
    }

    public function url(): string
    {
        return route('share.investor-pitch', ['token' => $this->token]);
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
        $this->lockSession();
    }

    /**
     * @param  Builder<InvestorPitchShare>  $query
     * @return Builder<InvestorPitchShare>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public static function sessionKey(string $token): string
    {
        return 'investor_pitch_share_unlocked:'.$token;
    }

    public static function isUnlocked(string $token): bool
    {
        return (bool) session(self::sessionKey($token), false);
    }

    public function unlockSession(): void
    {
        session([self::sessionKey($this->token) => true]);
    }

    public function lockSession(): void
    {
        session()->forget(self::sessionKey($this->token));
    }
}
