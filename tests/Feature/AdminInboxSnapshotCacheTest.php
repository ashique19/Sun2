<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Services\Admin\InboxSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxSnapshotCacheTest extends TestCase
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
            'external_user_id' => 'psid-snapshot',
            'customer_name' => 'Cache Customer',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function guest_is_redirected_from_snapshot_endpoint(): void
    {
        $this->getJson(route('admin.inbox.snapshot', ['ids' => [1]]))
            ->assertRedirect();
    }

    #[Test]
    public function moderator_cannot_fetch_inbox_snapshot(): void
    {
        Role::findOrCreate('moderator');
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator)
            ->getJson(route('admin.inbox.snapshot', ['ids' => [1]]))
            ->assertForbidden();
    }

    #[Test]
    public function staff_snapshot_returns_lookback_messages_for_requested_threads(): void
    {
        config(['channels.inbox.thread_lookback_hours' => 24]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $old = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_old',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'From last week',
            'sent_at' => now()->subHours(48),
        ]);

        $recent = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_recent',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Need this in cache',
            'sent_at' => now()->subMinutes(5),
        ]);

        $this->getJson(route('admin.inbox.snapshot', ['ids' => [$conversation->id]]))
            ->assertOk()
            ->assertJsonPath('threads.0.id', $conversation->id)
            ->assertJsonPath('threads.0.name', 'Cache Customer')
            ->assertJsonPath('threads.0.messages.0.body', 'Need this in cache')
            ->assertJsonMissing(['body' => 'From last week']);

        $this->assertSame($recent->id, (int) (new InboxSnapshotBuilder)->thread($conversation)['messages'][0]['id']);
        $this->assertNotSame($old->id, (int) (new InboxSnapshotBuilder)->thread($conversation)['messages'][0]['id']);
    }

    #[Test]
    public function inbox_html_includes_snapshot_hooks_and_open_thread_payload(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_open',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Hello from snapshot',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-snapshot-root')
            ->assertSeeHtml('data-inbox-snapshot-url="'.e(route('admin.inbox.snapshot')).'"')
            ->assertSeeHtml('data-inbox-conversation-id="'.$conversation->id.'"')
            ->assertSeeHtml('data-inbox-cache-thread')
            ->assertSeeHtml('data-inbox-opening-overlay')
            ->assertSee('Hello from snapshot')
            ->assertSeeHtml('data-inbox-open-thread="');
    }

    #[Test]
    public function inbox_snapshot_js_reads_local_storage_and_prefetches(): void
    {
        $source = file_get_contents(resource_path('js/inbox-snapshot-cache.js'));
        $boot = file_get_contents(resource_path('js/inbox-image-match.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('localStorage', $source);
        $this->assertStringContainsString('sun-inbox-cache:v', $source);
        $this->assertStringContainsString('data-inbox-cache-thread', $source);
        $this->assertStringContainsString('data-inbox-skip-opening', $source);
        $this->assertStringContainsString('prefetch', $source);
        $this->assertStringContainsString("import './inbox-snapshot-cache.js'", $boot);
    }
}
