<?php

namespace App\Support;

class InvestorPitchShare
{
    public static function isConfigured(): bool
    {
        return filled(config('investor_pitch.share_token'))
            && filled(config('investor_pitch.share_password'));
    }

    public static function token(): string
    {
        return (string) config('investor_pitch.share_token', '');
    }

    public static function password(): string
    {
        return (string) config('investor_pitch.share_password', '');
    }

    public static function url(): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        return route('share.investor-pitch', ['token' => self::token()]);
    }

    public static function sessionKey(string $token): string
    {
        return 'investor_pitch_share_unlocked:'.$token;
    }

    public static function isUnlocked(string $token): bool
    {
        return (bool) session(self::sessionKey($token), false);
    }

    public static function unlock(string $token): void
    {
        session([self::sessionKey($token) => true]);
    }

    public static function lock(string $token): void
    {
        session()->forget(self::sessionKey($token));
    }

    public static function tokenMatches(string $token): bool
    {
        $expected = self::token();

        return self::isConfigured()
            && $expected !== ''
            && hash_equals($expected, $token);
    }

    public static function passwordMatches(string $password): bool
    {
        $expected = self::password();

        return $expected !== '' && hash_equals($expected, $password);
    }
}
