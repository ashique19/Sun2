<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\MessengerConversationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxPollImageMatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function quiet_graph_poll_does_not_rebuild_image_match_state(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversationWithInboundPhoto();

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id);

        $matches = Mockery::mock(ChannelMessageImageMatchService::class);
        $matches->shouldReceive('indexHealth')->never();
        $matches->shouldReceive('indexStamp')->never();
        $matches->shouldReceive('renderState')->never();
        $matches->shouldReceive('resolve')->never();
        $this->app->instance(ChannelMessageImageMatchService::class, $matches);

        $this->mock(MessengerConversationSyncService::class, function ($mock) {
            $mock->shouldReceive('poll')->once()->andReturn([
                'ok' => true,
                'message' => 'Already up to date.',
                'conversations' => 0,
                'messages' => 0,
                'graph_threads' => 0,
            ]);
        });

        $component->call('pollSyncFromFacebook')
            ->assertSet('error', null)
            ->assertSet('statusMessage', null);
    }

    #[Test]
    public function graph_poll_rebuilds_image_match_state_when_messages_arrived(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversationWithInboundPhoto();

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id);

        $matches = Mockery::mock(ChannelMessageImageMatchService::class);
        $matches->shouldReceive('indexHealth')->once()->andReturn(null);
        $matches->shouldReceive('renderState')->andReturn(ChannelMessageImageMatchService::STATE_DONE);
        $matches->shouldReceive('suggestionRows')->andReturn([]);
        $matches->shouldReceive('failure')->andReturn(null);
        $this->app->instance(ChannelMessageImageMatchService::class, $matches);

        $this->mock(MessengerConversationSyncService::class, function ($mock) {
            $mock->shouldReceive('poll')->once()->andReturn([
                'ok' => true,
                'message' => 'Synced 1 conversation (1 message).',
                'conversations' => 1,
                'messages' => 1,
                'graph_threads' => 1,
            ]);
        });

        $component->call('pollSyncFromFacebook')
            ->assertSet('error', null);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversationWithInboundPhoto(): ChannelConversation
    {
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-poll-match',
            'customer_name' => 'Poll Match Customer',
            'last_inbound_at' => now(),
        ]);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_poll_photo',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/poll.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        return $conversation;
    }
}
