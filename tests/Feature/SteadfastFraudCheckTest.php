<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use App\Services\Couriers\SteadfastApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
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
    public function fraud_check_uses_score_endpoint_ratios_and_volume_band(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/01712345678' => Http::response([
                'status' => 200,
                'phone' => '01712345678',
                'delivery_ratio' => 92,
                'cancellation_ratio' => 7,
                'volume_band' => 'high',
                'volume_range' => '50+',
                'delivered_count' => 58,
                'cancelled_count' => 5,
                'total_reports' => 0,
                'fraud_categories' => [],
                'score' => null,
                'level' => null,
                'reasons' => [],
                'scoring_disabled' => true,
                'doubtful_reports' => false,
            ], 200),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712345678');

        $this->assertSame('score', $stats['data_type']);
        $this->assertSame(92, $stats['delivery_ratio']);
        $this->assertSame(92, $stats['success_ratio']);
        $this->assertSame(7, $stats['cancellation_ratio']);
        $this->assertSame('high', $stats['volume_band']);
        $this->assertSame('50+', $stats['volume_range']);
        $this->assertSame('high (50+)', $stats['volume_band_label']);
        $this->assertSame(58, $stats['total_delivered']);
        $this->assertSame(5, $stats['total_cancelled']);
        $this->assertSame(63, $stats['total_parcels']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://portal.packzy.com/api/v1/fraud_check/score/01712345678'
                && $request->hasHeader('Api-Key', 'test-key')
                && $request->hasHeader('Secret-Key', 'test-secret');
        });
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/fraud_check/017')
                && ! str_contains($request->url(), '/fraud_check/score/');
        });
    }

    #[Test]
    public function fraud_check_preserves_null_ratios_for_unknown_customers(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/*' => Http::response([
                'status' => 200,
                'phone' => '01712075185',
                'delivery_ratio' => null,
                'cancellation_ratio' => null,
                'volume_band' => 'none',
                'total_reports' => 0,
                'fraud_categories' => [],
                'scoring_disabled' => true,
            ], 200),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712075185');

        $this->assertNull($stats['delivery_ratio']);
        $this->assertNull($stats['cancellation_ratio']);
        $this->assertNull($stats['success_ratio']);
        $this->assertSame('none', $stats['volume_band']);
    }

    #[Test]
    public function fraud_check_falls_back_to_panel_when_score_api_fails(): void
    {
        config([
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/fraud_check/score/')) {
                return Http::response(['message' => 'Gone'], 404);
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'GET') {
                return Http::response(
                    '<html><input type="hidden" name="_token" value="csrf-login"></html>',
                    200
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

        $this->assertSame('counts', $stats['data_type']);
        $this->assertSame(12, $stats['total_parcels']);
        $this->assertSame(9, $stats['total_delivered']);
        $this->assertSame(75, $stats['success_ratio']);
    }

    #[Test]
    public function fraud_check_reads_score_payload_from_panel_when_score_api_500s(): void
    {
        config([
            'steadfast.fraud.email' => 'merchant@example.com',
            'steadfast.fraud.password' => 'secret',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/fraud_check/score/')) {
                return Http::response(['message' => 'Server Error'], 500);
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'GET') {
                return Http::response(
                    '<html><input type="hidden" name="_token" value="csrf-login"></html>',
                    200
                );
            }

            if ($url === 'https://steadfast.com.bd/login' && $request->method() === 'POST') {
                return Http::response('', 302, [
                    'Location' => 'https://steadfast.com.bd/user/orders',
                    'Set-Cookie' => 'steadfast_session=abc; Path=/; HttpOnly',
                ]);
            }

            if ($url === 'https://steadfast.com.bd/user/frauds/check/01581597404') {
                return Http::response([
                    'status' => 200,
                    'phone' => '01581597404',
                    'delivery_ratio' => 92,
                    'cancellation_ratio' => 7,
                    'volume_band' => 'high',
                    'volume_range' => '50+',
                    'delivered_count' => 58,
                    'cancelled_count' => 5,
                    'total_reports' => 0,
                    'fraud_categories' => [],
                    'scoring_disabled' => true,
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

        $stats = app(SteadfastApiClient::class)->fraudCheck('01581597404');

        $this->assertSame('score', $stats['data_type']);
        $this->assertSame(92, $stats['delivery_ratio']);
        $this->assertSame(58, $stats['total_delivered']);
        $this->assertSame(5, $stats['total_cancelled']);
        $this->assertSame('high (50+)', $stats['volume_band_label']);
    }

    #[Test]
    public function fraud_check_surfaces_rate_limit_errors(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/*' => Http::response(['message' => 'Too Many Requests'], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limit');

        app(SteadfastApiClient::class)->fraudCheck('01712345678');
    }

    #[Test]
    public function fraud_check_uses_short_message_for_html_api_errors(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/*' => Http::response('<html>Internal Server Error</html>', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Steadfast API error (HTTP 500).');

        app(SteadfastApiClient::class)->fraudCheck('01712345678');
    }

    #[Test]
    public function fraud_check_derives_ratios_from_counts_when_needed(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/*' => Http::response([
                'status' => 200,
                'phone' => '01712345678',
                'volume_range' => '50+',
                'delivered_count' => 58,
                'cancelled_count' => 5,
            ], 200),
        ]);

        $stats = app(SteadfastApiClient::class)->fraudCheck('01712345678');

        $this->assertSame(92, $stats['delivery_ratio']);
        $this->assertSame(7, $stats['cancellation_ratio']);
        $this->assertSame('50+', $stats['volume_band_label']);
    }

    #[Test]
    public function customer_lookup_surfaces_score_stats(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/fraud_check/score/01712345678' => Http::response([
                'status' => 200,
                'phone' => '01712345678',
                'delivery_ratio' => 80,
                'cancellation_ratio' => 20,
                'volume_band' => 'medium',
                'total_reports' => 1,
                'fraud_categories' => ['no_response' => 1],
                'scoring_disabled' => true,
            ], 200),
        ]);

        $result = app(CustomerLookupService::class)->lookup('01712345678');

        $this->assertNull($result['steadfast_error']);
        $this->assertSame('score', $result['steadfast']['data_type']);
        $this->assertSame(80, $result['steadfast']['delivery_ratio']);
        $this->assertSame('medium (6–20)', $result['steadfast']['volume_band_label']);
    }

    #[Test]
    public function order_form_shows_unknown_when_delivery_ratio_is_null(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01712075185',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 0,
            'orders' => collect(),
            'steadfast' => [
                'data_type' => 'score',
                'delivery_ratio' => null,
                'cancellation_ratio' => null,
                'success_ratio' => null,
                'volume_band' => 'none',
                'volume_band_label' => 'none (0 finished)',
                'total_reports' => 0,
                'fraud_categories' => [],
            ],
            'steadfast_error' => null,
        ]);
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ]);
        $this->app->instance(CustomerLookupService::class, $lookup);

        $this->actingAs($admin);

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712075185')
            ->call('lookupPhone')
            ->assertSee('Steadfast: no finished parcels yet')
            ->assertDontSee('Steadfast delivery success: 0%')
            ->assertSee('Volume none (0 finished)');
    }

    #[Test]
    public function order_form_shows_score_ratios_and_reports(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01712345678',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 0,
            'orders' => collect(),
            'steadfast' => [
                'data_type' => 'score',
                'delivery_ratio' => 92,
                'cancellation_ratio' => 7,
                'success_ratio' => 92,
                'volume_band' => 'high',
                'volume_range' => '50+',
                'volume_band_label' => 'high (50+)',
                'total_delivered' => 58,
                'total_cancelled' => 5,
                'total_parcels' => 63,
                'total_reports' => 2,
                'fraud_categories' => ['no_response' => 2],
            ],
            'steadfast_error' => null,
        ]);
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ]);
        $this->app->instance(CustomerLookupService::class, $lookup);

        $this->actingAs($admin);

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01712345678')
            ->call('lookupPhone')
            ->assertSee('Steadfast delivery: 92%')
            ->assertSee('Delivered 58')
            ->assertSee('Cancelled 5')
            ->assertSee('Volume high (50+)')
            ->assertSee('Reports 2');
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
