<?php

namespace App\Services\Auth;

use App\Contracts\Sms\SmsSender;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PasswordResetOtpService
{
    private const CACHE_PREFIX = 'password_reset_otp:';

    public function __construct(private SmsSender $sms) {}

    public function send(string $phone): void
    {
        if (! PhoneNumber::isValidDisplayMobile($phone)) {
            throw new RuntimeException('Please enter a valid Bangladesh mobile number.');
        }

        $normalized = PhoneNumber::normalize($phone);
        $code = app()->hasDebugModeEnabled()
            ? '123456'
            : (string) random_int(100000, 999999);
        $ttl = now()->addMinutes((int) config('checkout.otp_ttl_minutes', 10));

        Cache::put(self::CACHE_PREFIX.$normalized, [
            'code' => $code,
            'attempts' => 0,
        ], $ttl);

        $message = sprintf(
            'Your Sundoritoma password reset OTP is %s. Valid for %d minutes. Do not share this code.',
            $code,
            config('checkout.otp_ttl_minutes', 10),
        );

        $this->sms->send(PhoneNumber::display($phone), $message);
    }

    public function verify(string $phone, string $code): bool
    {
        $normalized = PhoneNumber::normalize($phone);
        $key = self::CACHE_PREFIX.$normalized;
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return false;
        }

        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;

        if ($payload['attempts'] > (int) config('checkout.otp_max_attempts', 5)) {
            Cache::forget($key);

            return false;
        }

        Cache::put($key, $payload, now()->addMinutes((int) config('checkout.otp_ttl_minutes', 10)));

        if (! hash_equals((string) ($payload['code'] ?? ''), trim($code))) {
            return false;
        }

        Cache::forget($key);

        return true;
    }
}
