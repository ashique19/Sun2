<?php

namespace Tests\Feature;

use App\Services\Storefront\CheckoutOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CheckoutOtpRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'checkout.otp_send_cooldown_seconds' => 60,
            'checkout.otp_send_max_per_hour' => 5,
            'app.debug' => false,
            'sms.driver' => 'mimsms',
            'sms.from' => 'Sundoritoma',
            'sms.mimsms' => [
                'api_url' => 'https://api.mimsms.com/api/V2/SMS',
                'username' => 'shop@example.com',
                'api_key' => 'secret-api-key',
                'sender_name' => 'Sundoritoma',
            ],
        ]);

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        RateLimiter::clear('otp_send_cooldown:checkout:1711112222');
        RateLimiter::clear('otp_send_hourly:checkout:1711112222');
    }

    #[Test]
    public function send_dispatches_sms_to_gateway_immediately(): void
    {
        app(CheckoutOtpService::class)->send('01711112222');

        Http::assertSent(function ($request): bool {
            return str_contains($request['Message'], 'order confirmation OTP')
                && $request['MobileNumber'] === '8801711112222';
        });
    }

    #[Test]
    public function send_is_rate_limited_within_cooldown_window(): void
    {
        $otp = app(CheckoutOtpService::class);
        $otp->send('01711112222');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Please wait');

        $otp->send('01711112222');
    }

    #[Test]
    public function send_blocks_after_hourly_cap(): void
    {
        config(['checkout.otp_send_cooldown_seconds' => 1]);

        $otp = app(CheckoutOtpService::class);
        $phone = '01711112222';

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::clear('otp_send_cooldown:checkout:1711112222');
            $otp->send($phone);
        }

        RateLimiter::clear('otp_send_cooldown:checkout:1711112222');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many OTP requests');

        $otp->send($phone);
    }
}
