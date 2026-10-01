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
    public function fraud_check_falls_back_to_merchant_panel_when_api_fails(): void
    {
        config([
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/*' => Http::response(['message' => 'Gone'], 404),
            'steadfast.com.bd/login' => Http::sequence()
                ->push('<html><input type="hidden" name="_token" value="csrf-login"></html>', 200)
                ->push('', 302, ['Set-Cookie' => 'steadfast_session=abc; Path=/; HttpOnly']),
            'steadfast.com.bd/user/frauds/check/01712345678' => Http::response([
                'total_delivered' => 5,
                'total_cancelled' => 1,
            ], 200),
            'steadfast.com.bd/user/frauds/check' => Http::response(
                '<html><meta name="csrf-token" content="csrf-logout"></html>',
                200
            ),
            'steadfast.com.bd/logout' => Http::response('', 302),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712345678');

        $this->assertSame(6, $stats['total_parcels']);
        $this->assertSame(5, $stats['total_delivered']);
        $this->assertSame(1, $stats['total_cancelled']);
        $this->assertSame(83, $stats['success_ratio']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://portal.packzy.com/api/v1/fraud_check/01712345678';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://steadfast.com.bd/user/frauds/check/01712345678';
        });
    }

    #[Test]
    public function fraud_check_uses_panel_when_only_panel_credentials_are_configured(): void
    {
        config([
            'steadfast.api_key' => null,
            'steadfast.secret_key' => null,
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake([
            'steadfast.com.bd/login' => Http::sequence()
                ->push('<html><meta name="csrf-token" content="csrf-login"></html>', 200)
                ->push('', 302, ['Set-Cookie' => 'steadfast_session=xyz; Path=/']),
            'steadfast.com.bd/user/frauds/check/01812345678' => Http::response([
                'total_delivered' => 2,
                'total_cancelled' => 2,
            ], 200),
            'steadfast.com.bd/user/frauds/check' => Http::response(
                '<html><meta name="csrf-token" content="csrf-logout"></html>',
                200
            ),
            'steadfast.com.bd/logout' => Http::response('', 302),
        ]);

        $client = app(SteadfastApiClient::class);

        $this->assertTrue($client->isFraudCheckAvailable());

        $stats = $client->fraudCheck('01812345678');

        $this->assertSame(4, $stats['total_parcels']);
        $this->assertSame(50, $stats['success_ratio']);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'portal.packzy.com');
        });
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
}
