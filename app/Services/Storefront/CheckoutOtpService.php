<?php

namespace App\Services\Storefront;

use App\Jobs\SendSmsJob;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class CheckoutOtpService
{
    private const CACHE_PREFIX = 'checkout_otp:';

    public function send(string $phone): void
    {
        $normalized = PhoneNumber::normalize($phone);

        if (! PhoneNumber::isValidBangladeshMobile($phone)) {
            throw new RuntimeException('Please enter a valid Bangladesh mobile number.');
        }

        $this->assertWithinSendLimits($normalized);

        $code = (app()->hasDebugModeEnabled() || app()->runningUnitTests())
            ? '123456'
            : (string) random_int(100000, 999999);
        $ttl = now()->addMinutes((int) config('checkout.otp_ttl_minutes', 10));

        Cache::put(self::CACHE_PREFIX.$normalized, [
            'code' => $code,
            'attempts' => 0,
        ], $ttl);

        $message = sprintf(
            'Your %s order confirmation OTP is %s. Valid for %d minutes. Do not share this code.',
            config('sms.from', 'Sundoritoma'),
            $code,
            config('checkout.otp_ttl_minutes', 10),
        );

        SendSmsJob::dispatch(PhoneNumber::display($phone), $message);
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

    private function assertWithinSendLimits(string $normalizedPhone): void
    {
        $cooldownSeconds = max(1, (int) config('checkout.otp_send_cooldown_seconds', 60));
        $hourlyMax = max(1, (int) config('checkout.otp_send_max_per_hour', 5));

        $cooldownKey = 'otp_send_cooldown:checkout:'.$normalizedPhone;
        $hourlyKey = 'otp_send_hourly:checkout:'.$normalizedPhone;

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $retry = RateLimiter::availableIn($cooldownKey);
            throw new RuntimeException("Please wait {$retry} seconds before requesting another OTP.");
        }

        if (RateLimiter::tooManyAttempts($hourlyKey, $hourlyMax)) {
            throw new RuntimeException('Too many OTP requests. Please try again later.');
        }

        RateLimiter::hit($cooldownKey, $cooldownSeconds);
        RateLimiter::hit($hourlyKey, 3600);
    }
}
