<?php

namespace Tests\Feature;

use App\Services\Admin\CustomerLookupService;
use App\Services\Couriers\SteadfastApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastFraudCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
            'steadfast.fraud.panel_url' => 'https://steadfast.com.bd',
            'steadfast.fraud.email' => null,
            'steadfast.fraud.password' => null,
        ]);
    }

    #[Test]
    public function fraud_check_normalizes_capitalized_total_parcels_from_api(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/01712345678' => Http::response([
                'Total_parcels' => 17,
                'total_delivered' => 7,
                'total_cancelled' => 10,
                'total_fraud_reports' => [],
            ], 200),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712345678');

        $this->assertSame(17, $stats['total_parcels']);
        $this->assertSame(7, $stats['total_delivered']);
        $this->assertSame(10, $stats['total_cancelled']);
        $this->assertSame(41, $stats['success_ratio']);
    }

    #[Test]
    public function fraud_check_reads_nested_data_payload(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/*' => Http::response([
                'status' => 200,
                'data' => [
                    'Total_parcels' => 4,
                    'total_delivered' => 3,
                    'total_cancelled' => 1,
                ],
            ], 200),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('+8801712345678');

        $this->assertSame(4, $stats['total_parcels']);
        $this->assertSame(3, $stats['total_delivered']);
        $this->assertSame(75, $stats['success_ratio']);
    }

    #[Test]
    public function fraud_check_prefers_panel_over_empty_api_zeros(): void
    {
        config([
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'portal.packzy.com')) {
                return Http::response([
                    'total_delivered' => 0,
                    'total_cancelled' => 0,
                    'Total_parcels' => 0,
                ], 200);
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'GET') {
                return Http::response(
                    '<html><input type="hidden" name="_token" value="csrf-login"></html>',
                    200,
                    ['Set-Cookie' => 'XSRF-TOKEN=xsrf; Path=/']
                );
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'POST') {
                return Http::response('', 302, [
                    'Location' => 'https://steadfast.com.bd/user/orders',
                    'Set-Cookie' => 'steadfast_session=abc; Path=/; HttpOnly',
                ]);
            }

            if ($url === 'https://steadfast.com.bd/user/frauds/check/01712075185') {
                return Http::response([
                    'total_delivered' => 9,
                    'total_cancelled' => 3,
                    'frauds' => [],
                ], 200, ['Content-Type' => 'application/json']);
            }

            if ($url === 'https://steadfast.com.bd/user/frauds/check') {
                return Http::response(
                    '<html><meta name="csrf-token" content="csrf-logout"></html>',
                    200
                );
            }

            if ($url === 'https://steadfast.com.bd/logout') {
                return Http::response('', 302);
            }

            return Http::response('unexpected '.$url, 500);
        });

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712075185');

        $this->assertSame(12, $stats['total_parcels']);
        $this->assertSame(9, $stats['total_delivered']);
        $this->assertSame(3, $stats['total_cancelled']);
        $this->assertSame(75, $stats['success_ratio']);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'portal.packzy.com');
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://steadfast.com.bd/user/frauds/check/01712075185';
        });
    }

    #[Test]
    public function fraud_check_falls_back_to_getbyphone_when_frauds_check_fails(): void
    {
        config([
            'steadfast.api_key' => null,
            'steadfast.secret_key' => null,
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'GET') {
                return Http::response(
                    '<html><meta name="csrf-token" content="csrf-login"></html>',
                    200
                );
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'POST') {
                return Http::response('', 302, [
                    'Location' => 'https://steadfast.com.bd/user/dashboard',
                    'Set-Cookie' => 'steadfast_session=xyz; Path=/',
                ]);
            }

            if ($url === 'https://steadfast.com.bd/user/frauds/check/01812345678') {
                return Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html']);
            }

            if ($url === 'https://steadfast.com.bd/user/consignment/getbyphone/01812345678') {
                return Http::response([
                    'total_delivered' => 2,
                    'total_cancelled' => 2,
                ], 200, ['Content-Type' => 'application/json']);
            }

            if ($url === 'https://steadfast.com.bd/user/frauds/check') {
                return Http::response(
                    '<html><meta name="csrf-token" content="csrf-logout"></html>',
                    200
                );
            }

            if ($url === 'https://steadfast.com.bd/logout') {
                return Http::response('', 302);
            }

            return Http::response('unexpected '.$url, 500);
        });

        $stats = app(SteadfastApiClient::class)->fraudCheck('01812345678');

        $this->assertSame(4, $stats['total_parcels']);
        $this->assertSame(50, $stats['success_ratio']);
    }

    #[Test]
    public function fraud_check_rejects_api_payload_without_count_keys(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/*' => Http::response([
                'status' => 200,
                'message' => 'ok',
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing delivery counts');

        app(SteadfastApiClient::class)->fraudCheck('01712345678');
    }

    #[Test]
    public function fraud_check_rejects_login_redirect_back_to_login(): void
    {
        config([
            'steadfast.api_key' => null,
            'steadfast.secret_key' => null,
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'wrong',
        ]);

        Http::fake([
            'steadfast.com.bd/login' => Http::sequence()
                ->push('<html><input type="hidden" name="_token" value="csrf-login"></html>', 200)
                ->push('', 302, ['Location' => 'https://steadfast.com.bd/login']),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STEADFAST_EMAIL / STEADFAST_PASSWORD');

        app(SteadfastApiClient::class)->fraudCheck('01712345678');
    }

    #[Test]
    public function customer_lookup_surfaces_normalized_steadfast_stats(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/01712345678' => Http::response([
                'Total_parcels' => 10,
                'total_delivered' => 8,
                'total_cancelled' => 2,
            ], 200),
        ]);

        $result = app(CustomerLookupService::class)->lookup('01712345678');

        $this->assertNull($result['steadfast_error']);
        $this->assertSame(10, $result['steadfast']['total_parcels']);
        $this->assertSame(80, $result['steadfast']['success_ratio']);
    }

    #[Test]
    public function customer_lookup_reports_when_fraud_check_is_not_configured(): void
    {
        config([
            'steadfast.api_key' => null,
            'steadfast.secret_key' => null,
            'steadfast.fraud.email' => null,
            'steadfast.fraud.password' => null,
        ]);

        $result = app(CustomerLookupService::class)->lookup('01712345678');

        $this->assertNull($result['steadfast']);
        $this->assertSame('Steadfast fraud check is not configured.', $result['steadfast_error']);
    }

    #[Test]
    public function config_maps_steadfast_email_and_password_env_keys(): void
    {
        putenv('STEADFAST_EMAIL=merchant@example.com');
        putenv('STEADFAST_PASSWORD=panel-secret');
        putenv('STEADFAST_FRAUD_EMAIL=ignored-fraud@example.com');
        putenv('STEADFAST_FRAUD_PASSWORD=ignored-fraud-secret');
        $_ENV['STEADFAST_EMAIL'] = 'merchant@example.com';
        $_ENV['STEADFAST_PASSWORD'] = 'panel-secret';
        $_ENV['STEADFAST_FRAUD_EMAIL'] = 'ignored-fraud@example.com';
        $_ENV['STEADFAST_FRAUD_PASSWORD'] = 'ignored-fraud-secret';
        $_SERVER['STEADFAST_EMAIL'] = 'merchant@example.com';
        $_SERVER['STEADFAST_PASSWORD'] = 'panel-secret';
        $_SERVER['STEADFAST_FRAUD_EMAIL'] = 'ignored-fraud@example.com';
        $_SERVER['STEADFAST_FRAUD_PASSWORD'] = 'ignored-fraud-secret';

        $config = require config_path('steadfast.php');

        $this->assertSame('merchant@example.com', $config['fraud']['email']);
        $this->assertSame('panel-secret', $config['fraud']['password']);
    }
}
