<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class InvestorPitchShare extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Store share passwords encrypted so admins can copy them again.
     * Legacy bcrypt hashes remain verifiable but are not recoverable.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if ($this->isLegacyPasswordHash($value)) {
                    return null;
                }

                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable) {
                    return null;
                }
            },
            set: fn (string $value): string => Crypt::encryptString($value),
        );
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

    public function plaintextPassword(): ?string
    {
        return $this->password;
    }

    public function passwordMatches(string $password): bool
    {
        $stored = (string) ($this->getAttributes()['password'] ?? '');

        if ($stored === '') {
            return false;
        }

        if ($this->isLegacyPasswordHash($stored)) {
            return Hash::check($password, $stored);
        }

        $plain = $this->plaintextPassword();

        return $plain !== null && hash_equals($plain, $password);
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

    private function isLegacyPasswordHash(string $value): bool
    {
        return str_starts_with($value, '$2y$')
            || str_starts_with($value, '$2a$')
            || str_starts_with($value, '$2b$');
    }
}
