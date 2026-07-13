<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\OrderDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderPrintLabelTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => '26765',
            'name' => 'Alyssa Russo',
            'phone' => '01832116687',
            'address' => "Ivy's Legacy, Apt # 3A",
            'city' => 'Dhaka',
            'area' => 'Gulshan',
            'status' => 'dispatched',
            'subtotal' => 0,
            'delivery_charge' => 0,
            'charge' => 0,
            'discount' => 0,
            'total' => 0,
            'cod_amount' => 0,
            'placed_at' => now(),
        ], $overrides));
    }

    public function test_print_parcel_id_prefers_consignment_id_over_tracking_code(): void
    {
        $order = $this->order([
            'courier_tracker' => 'SFR260713STA4C54B9BD',
            'courier_consignment_id' => '270697676',
        ]);

        $this->assertSame('270697676', $order->printParcelId());
    }

    public function test_print_parcel_id_reads_consignment_id_from_courier_api_logs(): void
    {
        $courier = Courier::query()->create([
            'name' => 'Steadfast',
            'slug' => 'steadfast',
            'is_active' => true,
            'is_default' => true,
        ]);

        $order = $this->order([
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFR260713STA4C54B9BD',
        ]);

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'consignment' => [
                    'consignment_id' => 270697676,
                    'tracking_code' => 'SFR260713STA4C54B9BD',
                ],
            ],
            'created_at' => now(),
        ]);

        $this->assertSame('270697676', $order->fresh()->printParcelId());
    }

    public function test_print_label_shows_parcel_id_not_tracking_code(): void
    {
        $this->actingAs($this->adminUser());

        $order = $this->order([
            'courier_tracker' => 'SFR260713STA4C54B9BD',
            'courier_consignment_id' => '270697676',
        ]);

        $response = $this->get(route('admin.orders.print', $order));

        $response->assertOk();
        $response->assertSee('Parcel ID', false);
        $response->assertSee('270697676', false);
        $response->assertDontSee('SFR260713STA4C54B9BD', false);
        $response->assertDontSee('CN#', false);
        $response->assertSee('font-weight: 900', false);
        $response->assertSee('width: 100%', false);
        $response->assertSee('min-width: 100%', false);
        $response->assertDontSee('width: 80mm', false);
    }

    public function test_print_label_uses_order_total_when_cod_amount_is_zero(): void
    {
        $this->actingAs($this->adminUser());

        $order = $this->order([
            'courier_consignment_id' => '270697676',
            'subtotal' => 3120,
            'total' => 3120,
            'cod_amount' => 0,
            'due_amount' => 0,
        ]);

        // Decimal cast makes "0.00", which is truthy for ?: and used to print 0.
        $this->assertSame('0.00', $order->cod_amount);
        $this->assertSame(3120.0, $order->collectableAmount());

        $this->get(route('admin.orders.print', $order))
            ->assertOk()
            ->assertSee('3,120 Tk', false);
    }

    public function test_print_selected_shows_parcel_id_brand_and_customer_name_per_order(): void
    {
        $this->actingAs($this->adminUser());

        $first = $this->order([
            'order_number' => '26765',
            'name' => 'Alyssa Russo',
            'courier_consignment_id' => '270697676',
        ]);
        $second = $this->order([
            'order_number' => '26766',
            'name' => 'Karim Hossain',
            'courier_consignment_id' => '270697677',
        ]);

        $response = $this->get(route('admin.orders.print-selected', [
            'ids' => $first->id.','.$second->id,
        ]));

        $response->assertOk();
        $response->assertSee('Parcel ID', false);
        $response->assertSee('270697676', false);
        $response->assertSee('270697677', false);
        $response->assertSee('Sundoritoma.com', false);
        $response->assertSee('Alyssa Russo', false);
        $response->assertSee('Karim Hossain', false);
        $response->assertSee('page-break-after: always', false);
        $response->assertDontSee('TOTAL DUE', false);
        $response->assertDontSee('CALL:', false);
    }

    public function test_print_selected_requires_ids(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.orders.print-selected'))
            ->assertNotFound();
    }

    public function test_print_selected_preserves_selection_order(): void
    {
        $this->actingAs($this->adminUser());

        $first = $this->order([
            'order_number' => '10001',
            'name' => 'First Customer',
            'courier_consignment_id' => '111',
        ]);
        $second = $this->order([
            'order_number' => '10002',
            'name' => 'Second Customer',
            'courier_consignment_id' => '222',
        ]);

        $html = $this->get(route('admin.orders.print-selected', [
            'ids' => $second->id.','.$first->id,
        ]))->assertOk()->getContent();

        $this->assertNotFalse(strpos($html, 'Second Customer'));
        $this->assertNotFalse(strpos($html, 'First Customer'));
        $this->assertLessThan(
            strpos($html, 'First Customer'),
            strpos($html, 'Second Customer')
        );
    }

    public function test_collectable_amount_prefers_positive_cod_over_total(): void
    {
        $order = $this->order([
            'total' => 3120,
            'cod_amount' => 2500,
            'due_amount' => 3120,
        ]);

        $this->assertSame(2500.0, $order->collectableAmount());
    }

    public function test_dispatch_extracts_steadfast_consignment_id(): void
    {
        $service = app(OrderDispatchService::class);
        $method = new ReflectionMethod(OrderDispatchService::class, 'extractConsignmentId');

        $id = $method->invoke($service, [
            'consignment' => [
                'consignment_id' => 270697676,
                'tracking_code' => 'SFR260713STA4C54B9BD',
            ],
        ]);

        $this->assertSame('270697676', $id);
    }
}
