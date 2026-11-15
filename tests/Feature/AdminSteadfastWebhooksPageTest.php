<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminSteadfastWebhooks;
use App\Models\Courier;
use App\Models\Order;
use App\Models\SteadfastWebhookLog;
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
    public function see_all_page_lists_raw_inbound_logs_including_unmatched(): void
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

        $matchedPayload = [
            'notification_type' => 'delivery_status',
            'status' => 'in_transit',
            'invoice' => $order->order_number,
        ];

        SteadfastWebhookLog::factory()->create([
            'order_id' => $order->id,
            'outcome' => SteadfastWebhookLog::OUTCOME_PROCESSED,
            'http_status' => 200,
            'notification_type' => 'delivery_status',
            'invoice' => $order->order_number,
            'consignment_id' => '270697699',
            'payload' => $matchedPayload,
            'raw_body' => json_encode($matchedPayload),
            'created_at' => now()->subHour(),
        ]);

        $unmatchedPayload = [
            'notification_type' => 'tracking_update',
            'invoice' => 'GHOST-99',
            'tracking_message' => 'No order for this invoice',
        ];

        SteadfastWebhookLog::factory()->create([
            'order_id' => null,
            'outcome' => SteadfastWebhookLog::OUTCOME_UNMATCHED,
            'http_status' => 200,
            'notification_type' => 'tracking_update',
            'invoice' => 'GHOST-99',
            'consignment_id' => null,
            'payload' => $unmatchedPayload,
            'raw_body' => json_encode($unmatchedPayload),
            'created_at' => now()->subMinutes(10),
        ]);

        $this->get(route('admin.couriers.webhooks'))
            ->assertOk()
            ->assertSeeLivewire(AdminSteadfastWebhooks::class);

        Livewire::test(AdminSteadfastWebhooks::class)
            ->assertSee('Steadfast webhooks')
            ->assertSee('Every inbound POST after auth')
            ->assertSee('Order #'.$order->order_number)
            ->assertSee('Page Webhook Customer')
            ->assertSee('processed')
            ->assertSee('unmatched')
            ->assertSee('invoice GHOST-99')
            ->assertSee('Raw payload')
            ->assertSee('in_transit')
            ->assertSee('No order for this invoice')
            ->assertDontSeeHtml('wire:click="dismiss');
    }

    #[Test]
    public function empty_log_page_explains_there_are_no_saved_webhooks(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminSteadfastWebhooks::class)
            ->assertSee('No inbound Steadfast webhooks saved yet.');
    }
}
