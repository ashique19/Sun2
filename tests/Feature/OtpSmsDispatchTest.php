<?php

namespace Tests\Feature;

use App\Services\Auth\PasswordResetOtpService;
use App\Services\Storefront\CheckoutOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OtpSmsDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'checkout.otp_send_cooldown_seconds' => 60,
            'checkout.otp_send_max_per_hour' => 5,
            'app.debug' => true,
            'sms.driver' => 'mimsms',
            'sms.from' => 'Sundoritoma',
            'sms.mimsms' => [
                'api_url' => 'https://api.mimsms.com/api/V2/SMS',
                'username' => 'shop@example.com',
                'api_key' => 'secret-api-key',
                'sender_name' => 'Sundoritoma',
            ],
        ]);

        RateLimiter::clear('otp_send_cooldown:checkout:1711112222');
        RateLimiter::clear('otp_send_hourly:checkout:1711112222');
        RateLimiter::clear('otp_send_cooldown:password:1711112222');
        RateLimiter::clear('otp_send_hourly:password:1711112222');
    }

    #[Test]
    public function checkout_otp_sends_sms_synchronously_without_queueing(): void
    {
        Queue::fake();

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(CheckoutOtpService::class)->send('01711112222');

        Queue::assertNothingPushed();

        Http::assertSent(function (Request $request): bool {
            return $request['MobileNumber'] === '8801711112222'
                && $request['TransactionType'] === 'T'
                && str_contains($request['Message'], '123456');
        });
    }

    #[Test]
    public function password_reset_otp_sends_sms_synchronously_without_queueing(): void
    {
        Queue::fake();

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(PasswordResetOtpService::class)->send('01711112222');

        Queue::assertNothingPushed();

        Http::assertSent(function (Request $request): bool {
            return $request['MobileNumber'] === '8801711112222'
                && str_contains($request['Message'], 'password reset OTP');
        });
    }

    #[Test]
    public function checkout_otp_surfaces_gateway_failures_to_caller(): void
    {
        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Failed',
                'responseResult' => 'Invalid sender name',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiMSMS gateway rejected the message: Invalid sender name');

        app(CheckoutOtpService::class)->send('01711112222');
    }

    #[Test]
    public function sms_send_attempts_are_logged_with_masked_phone(): void
    {
        Log::spy();

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(CheckoutOtpService::class)->send('01711112222');

        Log::shouldHaveReceived('info')
            ->with('SMS send attempt', \Mockery::on(function (array $context): bool {
                return $context['type'] === 'transactional'
                    && $context['driver'] === 'mimsms'
                    && $context['phone'] === '***2222';
            }))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('SMS send succeeded', \Mockery::type('array'))
            ->once();
    }

    #[Test]
    public function sms_send_failures_are_logged(): void
    {
        Log::spy();

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Failed',
                'responseResult' => 'Invalid sender name',
            ]),
        ]);

        try {
            app(CheckoutOtpService::class)->send('01711112222');
        } catch (RuntimeException) {
            // expected
        }

        Log::shouldHaveReceived('error')
            ->with('SMS send failed', \Mockery::on(function (array $context): bool {
                return $context['type'] === 'transactional'
                    && $context['driver'] === 'mimsms'
                    && str_contains($context['error'], 'Invalid sender name');
            }))
            ->once();
    }
}
