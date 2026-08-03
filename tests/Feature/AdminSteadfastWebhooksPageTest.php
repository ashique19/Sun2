<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminSteadfastWebhooks;
use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSteadfastWebhooksPageTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function see_all_page_lists_webhooks_and_can_dismiss(): void
    {
        $this->actingAs($this->adminUser());

        $courier = Courier::query()->firstOrCreate(
            ['slug' => 'steadfast'],
            [
                'name' => 'Steadfast',
                'charge' => 60,
                'osd_charge' => 110,
                'cod_percentage' => 1,
                'is_active' => true,
                'is_default' => true,
            ],
        );

        $order = Order::query()->create([
            'order_number' => 'WH-PAGE-1',
            'name' => 'Page Webhook Customer',
            'phone' => '01710000999',
            'address' => 'Dhaka',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'total' => 1000,
            'courier_id' => $courier->id,
            'courier_tracker' => 'SFR_PAGE',
            'courier_consignment_id' => '270697699',
            'placed_at' => now()->subDay(),
            'dispatch_date' => now()->subDay(),
        ]);

        $entry = CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'status' => 'in_transit',
                'invoice' => $order->order_number,
            ],
            'created_at' => now()->subHour(),
        ]);

        $this->get(route('admin.couriers.webhooks'))
            ->assertOk()
            ->assertSeeLivewire(AdminSteadfastWebhooks::class);

        Livewire::test(AdminSteadfastWebhooks::class)
            ->assertSee('Steadfast webhooks')
            ->assertSee('Order #'.$order->order_number)
            ->assertSee('Parcel 270697699')
            ->assertSee('https://steadfast.com.bd/user/consignment/270697699', false)
            ->assertSeeHtml('wire:click="dismiss('.$entry->id.')"')
            ->assertDontSeeHtml('>Parcel ↗</a>')
            ->call('dismiss', $entry->id)
            ->assertDontSee('Order #'.$order->order_number)
            ->assertSee('No inbound Steadfast webhooks in the last 2 days.');

        $this->assertNotNull($entry->fresh()->inbox_dismissed_at);
    }
}
