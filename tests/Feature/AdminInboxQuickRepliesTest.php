<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Livewire\Admin\AdminInboxQuickReplies;
use App\Models\ChannelConversation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxQuickRepliesTest extends TestCase
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
    public function staff_can_manage_quick_replies_from_admin_page(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminInboxQuickReplies::class)
            ->assertSee('Quick replies')
            ->set('replies', [
                ['label' => 'Hi', 'body' => 'Hello there'],
                ['label' => 'Bye', 'body' => 'Thank you'],
            ])
            ->call('save')
            ->assertSet('statusMessage', 'Quick replies saved.');

        $stored = app(InboxQuickReplyService::class)->all();
        $this->assertCount(2, $stored);
        $this->assertSame('Hi', $stored[0]['label']);
        $this->assertSame('Hello there', $stored[0]['body']);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-1',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Hi')
            ->call('insertQuickReply', 0)
            ->assertSet('replyText', 'Hello there');
    }

    #[Test]
    public function reset_defaults_clears_stored_overrides(): void
    {
        $this->actingAs($this->adminUser());

        Setting::putValue(InboxQuickReplyService::SETTING_KEY, json_encode([
            ['label' => 'Custom', 'body' => 'Custom body'],
        ]), 'channels');

        config([
            'channels.inbox.quick_replies' => [
                ['label' => 'Default', 'body' => 'Default body'],
            ],
        ]);

        Livewire::test(AdminInboxQuickReplies::class)
            ->assertSet('replies.0.label', 'Custom')
            ->call('resetDefaults')
            ->assertSet('replies.0.label', 'Default')
            ->assertSet('replies.0.body', 'Default body');
    }
}
