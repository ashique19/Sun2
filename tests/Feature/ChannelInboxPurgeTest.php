<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Channels\ChannelInboxPurgeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChannelInboxPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(array $overrides = []): ChannelConversation
    {
        return ChannelConversation::query()->create(array_merge([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-'.uniqid(),
            'customer_name' => 'Customer',
            'last_inbound_at' => now()->subDays(10),
            'last_outbound_at' => now()->subDays(10),
        ], $overrides));
    }

    private function draftOrder(ChannelConversation $conversation, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Draft Customer',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 0,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 0,
            'cod_amount' => 0,
            'due_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => Order::STATUS_DRAFT,
            'placed_at' => now()->subDays(10),
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $conversation->id,
        ], $overrides));

        $conversation->update(['draft_order_id' => $order->id]);

        return $order;
    }

    public function test_purge_deletes_stale_conversations_and_messages(): void
    {
        $stale = $this->conversation([
            'external_user_id' => 'PSID_STALE',
            'last_inbound_at' => now()->subDays(10),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $stale->id,
            'external_message_id' => 'm_stale',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Old hello',
            'sent_at' => now()->subDays(10),
        ]);

        $fresh = $this->conversation([
            'external_user_id' => 'PSID_FRESH',
            'last_inbound_at' => now()->subDay(),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $fresh->id,
            'external_message_id' => 'm_fresh',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Recent hello',
            'sent_at' => now()->subDay(),
        ]);

        $result = app(ChannelInboxPurgeService::class)->purge(retentionDays: 7);

        $this->assertSame(1, $result['purged']);
        $this->assertNull(ChannelConversation::query()->find($stale->id));
        $this->assertSame(0, ChannelMessage::query()->where('external_message_id', 'm_stale')->count());
        $this->assertNotNull(ChannelConversation::query()->find($fresh->id));
        $this->assertSame(1, ChannelMessage::query()->where('external_message_id', 'm_fresh')->count());
    }

    public function test_purge_discards_linked_ai_drafts_but_keeps_confirmed_orders(): void
    {
        $stale = $this->conversation(['external_user_id' => 'PSID_DRAFT']);
        $draft = $this->draftOrder($stale);

        $confirmedConversation = $this->conversation([
            'external_user_id' => 'PSID_CONFIRMED',
            'last_inbound_at' => now()->subDays(12),
            'draft_order_id' => null,
        ]);
        $confirmed = Order::query()->create([
            'order_number' => '9001',
            'name' => 'Confirmed',
            'phone' => '01712345678',
            'address' => 'Banani',
            'city' => 'Dhaka',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now()->subDays(12),
            'placed_via' => Order::PLACED_VIA_MESSENGER,
            'channel_conversation_id' => $confirmedConversation->id,
        ]);

        $result = app(ChannelInboxPurgeService::class)->purge(retentionDays: 7);

        $this->assertSame(2, $result['purged']);
        $this->assertSame(1, $result['drafts_discarded']);
        $this->assertNull(Order::query()->find($draft->id));
        $this->assertNotNull($confirmed->fresh());
        $this->assertNull($confirmed->fresh()->channel_conversation_id);
    }

    public function test_artisan_purge_command_and_dry_run(): void
    {
        $this->conversation(['external_user_id' => 'PSID_CMD']);

        Artisan::call('channels:purge-inbox', ['--days' => 7, '--dry-run' => true]);
        $this->assertStringContainsString('Would purge 1 conversation', Artisan::output());
        $this->assertSame(1, ChannelConversation::query()->count());

        Artisan::call('channels:purge-inbox', ['--days' => 7]);
        $this->assertStringContainsString('Purged 1 conversation', Artisan::output());
        $this->assertSame(0, ChannelConversation::query()->count());
    }

    public function test_inbox_mount_purges_stale_conversations(): void
    {
        Cache::flush();

        $stale = $this->conversation(['external_user_id' => 'PSID_MOUNT']);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $stale->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Gone',
            'sent_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->assertDontSee('Gone');

        $this->assertNull(ChannelConversation::query()->find($stale->id));
    }

    public function test_purge_schedule_is_registered_when_enabled(): void
    {
        config(['channels.inbox.purge_schedule_enabled' => true]);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'channels:purge-inbox'));

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->filtersPass(app()));
    }
}
