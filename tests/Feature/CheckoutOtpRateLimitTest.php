<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Services\Storefront\CheckoutOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            'app.debug' => true,
        ]);

        RateLimiter::clear('otp_send_cooldown:checkout:1711112222');
        RateLimiter::clear('otp_send_hourly:checkout:1711112222');
    }

    #[Test]
    public function send_dispatches_queued_sms_job(): void
    {
        Queue::fake();

        app(CheckoutOtpService::class)->send('01711112222');

        Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job): bool {
            return str_contains($job->message, '123456')
                && str_contains($job->phone, '1711112222');
        });
    }

    #[Test]
    public function send_is_rate_limited_within_cooldown_window(): void
    {
        Queue::fake();

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
        Queue::fake();

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
