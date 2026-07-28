<?php

namespace Tests\Feature;

use App\Events\InboxMessageStored;
use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxBroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function inbox_private_channel_authorization_is_staff_only(): void
    {
        $admin = $this->userWithRole('admin');
        $moderator = $this->userWithRole('moderator');

        $this->assertTrue(AdminAccess::isStaffAdmin($admin));
        $this->assertFalse(AdminAccess::isStaffAdmin($moderator));

        $authorize = function (?User $user) {
            if (! $user) {
                return false;
            }

            return AdminAccess::isStaffAdmin($user)
                ? ['id' => $user->id, 'name' => $user->name]
                : false;
        };

        $this->assertSame(
            ['id' => $admin->id, 'name' => $admin->name],
            $authorize($admin),
        );
        $this->assertFalse($authorize($moderator));
        $this->assertFalse($authorize(null));
    }

    #[Test]
    public function inbox_message_stored_broadcasts_on_private_admin_inbox_channel(): void
    {
        $event = new InboxMessageStored(
            conversationId: 9,
            messageId: 42,
            direction: ChannelMessage::DIRECTION_INBOUND,
            channel: ChannelConversation::CHANNEL_MESSENGER,
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.inbox', $channels[0]->name);
        $this->assertSame('InboxMessageStored', $event->broadcastAs());
        $this->assertSame([
            'conversation_id' => 9,
            'message_id' => 42,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
        ], $event->broadcastWith());
    }

    #[Test]
    public function refresh_from_realtime_shows_new_inbound_without_graph_poll(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-rt',
            'customer_name' => 'Realtime Customer',
            'last_inbound_at' => now(),
            'last_read_at' => now()->subMinute(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Already open',
            'sent_at' => now()->subMinute(),
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Already open');

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Pushed by Echo',
            'sent_at' => now(),
        ]);
        $conversation->update(['last_inbound_at' => now()]);

        $component->call('refreshFromRealtime', $conversation->id)
            ->assertSee('Already open')
            ->assertSee('Pushed by Echo');

        $this->assertNotNull($conversation->fresh()->last_read_at);
    }

    #[Test]
    public function realtime_mode_uses_slower_graph_poll_interval(): void
    {
        config([
            'channels.inbox.realtime_enabled' => true,
            'channels.inbox.graph_poll_seconds_realtime' => 60,
        ]);

        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(AdminInbox::class)
            ->assertSeeHtml('wire:poll.60s.visible="pollSyncFromFacebook"')
            ->assertSeeHtml('data-inbox-realtime="1"')
            ->assertSee('Live updates when Reverb is running');
    }

    #[Test]
    public function poll_fallback_keeps_ten_second_interval_when_realtime_off(): void
    {
        config([
            'channels.inbox.realtime_enabled' => false,
            'channels.inbox.graph_poll_seconds' => 10,
        ]);

        $this->actingAs($this->userWithRole('admin'));

        Livewire::test(AdminInbox::class)
            ->assertSeeHtml('wire:poll.10s.visible="pollSyncFromFacebook"')
            ->assertSeeHtml('data-inbox-realtime="0"')
            ->assertSee('Syncs from Facebook every 10s');
    }
}
