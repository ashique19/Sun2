<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Channels\ChannelReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxTest extends TestCase
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
            'external_user_id' => 'psid-1',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function opening_a_conversation_marks_it_read(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation(['last_read_at' => null]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('selectedConversationId', $conversation->id);

        $this->assertNotNull($conversation->fresh()->last_read_at);
    }

    #[Test]
    public function it_can_send_a_reply_from_the_inbox(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello',
            'sent_at' => now(),
        ]);

        $replies = Mockery::mock(ChannelReplyService::class);
        $replies->shouldReceive('sendText')
            ->once()
            ->andReturn([
                'ok' => true,
                'message' => null,
                'error' => null,
                'outside_window' => false,
            ]);
        $this->app->instance(ChannelReplyService::class, $replies);

        Livewire::test(AdminInbox::class)
            ->set('selectedConversationId', $conversation->id)
            ->set('replyText', 'Thanks!')
            ->call('sendReply')
            ->assertSet('replyText', '')
            ->assertSet('message', 'Reply sent.');
    }

    #[Test]
    public function empty_inbox_explains_status_instead_of_silent_blank(): void
    {
        config([
            'facebook.messenger.enabled' => true,
            'facebook.messenger.verify_token' => '',
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => '',
            'app.url' => 'https://example.test',
        ]);

        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->assertSee('Inbox status')
            ->assertSee('No conversations stored yet')
            ->assertSee('/api/webhooks/messenger')
            ->assertSee('Verify token configured')
            ->assertSee('does not pull chat history from Facebook');
    }

    #[Test]
    public function filtered_empty_inbox_offers_clear_filters_when_rows_exist(): void
    {
        $this->actingAs($this->adminUser());
        $this->conversation();

        Livewire::test(AdminInbox::class)
            ->set('channel', 'whatsapp')
            ->assertSee('Clear filters')
            ->assertSee('No conversations match the current filters')
            ->call('clearFilters')
            ->assertSet('channel', '')
            ->assertSee('Karim');
    }
}
