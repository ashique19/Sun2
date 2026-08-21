<?php

namespace Tests\Feature;

use App\Services\Couriers\PathaoMerchantSuccessClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PathaoMerchantSuccessClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'pathao.scrap' => true,
            'pathao.username' => 'merchant@example.com',
            'pathao.password' => 'secret',
            'pathao.merchant_base_url' => 'https://merchant.pathao.com',
            'pathao.scrap_timeout' => 5,
        ]);
    }

    #[Test]
    public function it_returns_count_based_success_stats(): void
    {
        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => Http::response([
                'access_token' => 'portal-token',
            ], 200),
            'https://merchant.pathao.com/api/v1/user/success' => Http::response([
                'data' => [
                    'customer' => [
                        'successful_delivery' => 8,
                        'total_delivery' => 10,
                    ],
                ],
            ], 200),
        ]);

        $stats = app(PathaoMerchantSuccessClient::class)->successCheck('01712345678');

        $this->assertSame('counts', $stats['data_type']);
        $this->assertSame(80, $stats['success_ratio']);
        $this->assertSame(8, $stats['total_delivered']);
        $this->assertSame(10, $stats['total_parcels']);
        $this->assertSame(2, $stats['total_cancelled']);
        $this->assertSame('80% success', $stats['label']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://merchant.pathao.com/api/v1/login'
                && $request['username'] === 'merchant@example.com';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://merchant.pathao.com/api/v1/user/success'
                && $request->hasHeader('Authorization', 'Bearer portal-token')
                && $request['phone'] === '01712345678';
        });
    }

    #[Test]
    public function it_returns_rating_based_stats_when_counts_missing(): void
    {
        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => Http::response([
                'access_token' => 'portal-token',
            ], 200),
            'https://merchant.pathao.com/api/v1/user/success' => Http::response([
                'data' => [
                    'customer' => [
                        'customer_rating' => 'excellent_customer',
                        'risk_level' => 'low',
                        'success_rate' => 95,
                    ],
                ],
            ], 200),
        ]);

        $stats = app(PathaoMerchantSuccessClient::class)->successCheck('01712345678');

        $this->assertSame('rating', $stats['data_type']);
        $this->assertSame('excellent_customer', $stats['customer_rating']);
        $this->assertSame('low', $stats['risk_level']);
        $this->assertSame(95, $stats['success_ratio']);
        $this->assertSame('Excellent Customer (Low risk)', $stats['label']);
    }

    #[Test]
    public function it_reuses_cached_portal_token(): void
    {
        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => Http::response([
                'access_token' => 'portal-token',
            ], 200),
            'https://merchant.pathao.com/api/v1/user/success' => Http::response([
                'data' => [
                    'customer' => [
                        'successful_delivery' => 1,
                        'total_delivery' => 1,
                    ],
                ],
            ], 200),
        ]);

        $client = app(PathaoMerchantSuccessClient::class);
        $client->successCheck('01712345678');
        $client->successCheck('01812345678');

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_throws_when_scrap_disabled(): void
    {
        config(['pathao.scrap' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pathao success scraping is not configured.');

        app(PathaoMerchantSuccessClient::class)->successCheck('01712345678');
    }
}
