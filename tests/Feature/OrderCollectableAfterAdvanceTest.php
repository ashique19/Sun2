<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Admin\OrderDispatchService;
use App\Services\Couriers\SteadfastApiClient;
use App\Services\Orders\OrderPaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCollectableAfterAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function seedPaymentMethod(string $code = 'bkash'): void
    {
        PaymentMethod::query()->firstOrCreate(
            ['code' => $code],
            ['name' => strtoupper($code), 'is_active' => true],
        );
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ADV-'.uniqid(),
            'name' => 'Advance Customer',
            'phone' => '01710000999',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'area' => 'Gulshan',
            'status' => 'new',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'charge' => 0,
            'discount' => 0,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'placed_at' => now(),
            'placed_via' => Order::PLACED_VIA_ADMIN,
        ], $overrides));
    }

    #[Test]
    public function collectable_amount_is_residual_after_partial_advance(): void
    {
        $this->seedPaymentMethod();
        $order = $this->order();

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 500,
            kind: 'advance',
        );

        $order->refresh();

        $this->assertSame(500.0, (float) $order->paid_amount);
        $this->assertSame(580.0, (float) $order->due_amount);
        $this->assertSame(580.0, (float) $order->cod_amount);
        $this->assertSame(580.0, $order->collectableAmount());
    }

    #[Test]
    public function collectable_amount_is_zero_after_full_advance(): void
    {
        $this->seedPaymentMethod();
        $order = $this->order();

        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 1080,
            kind: 'advance',
        );

        $order->refresh();

        $this->assertSame(1080.0, (float) $order->paid_amount);
        $this->assertSame(0.0, (float) $order->due_amount);
        $this->assertSame(0.0, (float) $order->cod_amount);
        $this->assertSame(0.0, $order->collectableAmount());
    }

    #[Test]
    public function collectable_amount_uses_paid_residual_when_cod_amount_is_stale(): void
    {
        $order = $this->order([
            'paid_amount' => 500,
            'due_amount' => 580,
            // Stale booking COD left at the original bill — must not win over paid residual.
            'cod_amount' => 1080,
            'payment_status' => 'partial',
        ]);

        $this->assertSame(580.0, $order->collectableAmount());
    }

    #[Test]
    public function steadfast_dispatch_sends_residual_cod_after_advance(): void
    {
        $this->seedPaymentMethod();

        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_active' => true,
            'is_default' => true,
        ]);

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://steadfast.test',
        ]);

        $order = $this->order();
        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 500,
            kind: 'advance',
        );

        $steadfast = Mockery::mock(SteadfastApiClient::class);
        $steadfast->shouldReceive('createOrder')
            ->once()
            ->withArgs(function (array $payload): bool {
                return (float) ($payload['cod_amount'] ?? -1) === 580.0;
            })
            ->andReturn([
                'consignment' => [
                    'consignment_id' => 999001,
                    'tracking_code' => 'SFR-ADV-TEST',
                ],
            ]);
        $this->app->instance(SteadfastApiClient::class, $steadfast);

        $updated = app(OrderDispatchService::class)->dispatchViaApi(
            $order->fresh(),
            'steadfast',
        );

        $this->assertSame('SFR-ADV-TEST', $updated->courier_tracker);
        $this->assertSame(580.0, $updated->collectableAmount());
    }

    #[Test]
    public function steadfast_dispatch_sends_zero_cod_when_fully_prepaid(): void
    {
        $this->seedPaymentMethod();

        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_active' => true,
            'is_default' => true,
        ]);

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://steadfast.test',
        ]);

        $order = $this->order();
        app(OrderPaymentRecorder::class)->record(
            order: $order,
            method: 'bkash',
            amount: 1080,
            kind: 'advance',
        );

        $steadfast = Mockery::mock(SteadfastApiClient::class);
        $steadfast->shouldReceive('createOrder')
            ->once()
            ->withArgs(function (array $payload): bool {
                return (float) ($payload['cod_amount'] ?? -1) === 0.0;
            })
            ->andReturn([
                'consignment' => [
                    'consignment_id' => 999002,
                    'tracking_code' => 'SFR-PREPAID-TEST',
                ],
            ]);
        $this->app->instance(SteadfastApiClient::class, $steadfast);

        $updated = app(OrderDispatchService::class)->dispatchViaApi(
            $order->fresh(),
            'steadfast',
        );

        $this->assertSame(0.0, $updated->collectableAmount());
    }
}
