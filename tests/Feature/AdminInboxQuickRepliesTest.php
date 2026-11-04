<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Livewire\Admin\AdminInboxQuickReplies;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Setting;
use App\Models\User;
use App\Services\Channels\InboxQuickReplyMatcher;
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
    public function quick_replies_page_keeps_working_admin_top_nav(): void
    {
        $this->actingAs($this->adminUser());

        $html = $this->get(route('admin.inbox.quick-replies'))
            ->assertOk()
            ->assertSee('Quick replies')
            ->assertSee('Back to Inbox')
            ->assertSeeHtml('id="admin-mobile-top-bar"')
            ->assertSeeHtml('data-admin-shortcut="products"')
            ->assertSeeHtml('data-admin-shortcut="orders"')
            ->assertSeeHtml('data-admin-shortcut="inbox"')
            ->assertDontSeeHtml('data-inbox-snapshot-root')
            ->assertDontSeeHtml('data-inbox-mobile-thread="1"')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/inbox"[^>]*wire:navigate/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/inbox"[^>]*data-admin-shortcut="inbox"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/orders\/new"[^>]*data-admin-shortcut="orders"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/products"[^>]*data-admin-shortcut="products"/',
            $html,
        );
        $this->assertStringContainsString('min-h-screen', $html);
        $this->assertStringContainsString('id="admin-mobile-nav-toggle"', $html);
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
            ->assertSeeHtml('data-inbox-quick-replies')
            ->assertSeeHtml('data-inbox-quick-reply-body="Hello there"')
            ->assertSeeHtml('$wire.$set(\'replyText\', body, false)')
            ->assertSeeHtml('data-inbox-reply-text')
            ->assertDontSeeHtml('wire:click="insertQuickReply')
            ->set('replyText', 'Existing draft')
            ->call('insertQuickReply', 0)
            ->assertSet('replyText', 'Hello there');
    }

    #[Test]
    public function multiline_quick_reply_keeps_line_breaks_in_composer(): void
    {
        $this->actingAs($this->adminUser());

        $body = "আসসালামু আলাইকুম\nআপনার ঠিকানা দিবেন?\nধন্যবাদ";

        Setting::putValue(InboxQuickReplyService::SETTING_KEY, json_encode([
            ['label' => 'Address', 'body' => $body],
        ], JSON_UNESCAPED_UNICODE), 'channels');

        $this->assertSame($body, app(InboxQuickReplyService::class)->all()[0]['body']);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-ml',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('<textarea')
            ->assertSeeHtml('data-inbox-quick-reply-body="'.e($body).'"')
            ->call('insertQuickReply', 0)
            ->assertSet('replyText', $body)
            ->assertSeeHtml('rows="3"');
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

    #[Test]
    public function staff_can_crud_reply_tags_used_for_suggestions(): void
    {
        $this->actingAs($this->adminUser());

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টটা দেখতে চান?'],
        ]);
        $replyId = $replies[0]['id'];

        Livewire::test(AdminInboxQuickReplies::class)
            ->assertSeeHtml('data-inbox-quick-reply-tags')
            ->assertSee('Reply tags')
            ->assertSee('No tags yet')
            ->set('newTags.'.$replyId, 'দাম কত')
            ->call('addTag', $replyId)
            ->assertSet('statusMessage', 'Reply tag saved.')
            ->assertSeeHtml('data-inbox-quick-reply-tag="')
            ->assertDontSee('No tags yet');

        $example = InboxQuickReplyExample::query()->first();
        $this->assertNotNull($example);
        $this->assertSame($replyId, $example->quick_reply_id);
        $this->assertSame('দাম কত', $example->example_text);

        Livewire::test(AdminInboxQuickReplies::class)
            ->set('tagEdits.'.$example->id, 'এটা কত')
            ->call('updateTag', $example->id)
            ->assertSet('statusMessage', 'Reply tag updated.');

        $this->assertSame('এটা কত', $example->fresh()->example_text);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-tag-crud',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
        $later = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'এটা কত',
            'sent_at' => now(),
        ]);
        $matches = app(InboxQuickReplyMatcher::class)->matchThread(
            $conversation->fresh()->load('messages'),
            $replies,
        );
        $this->assertSame($replyId, $matches[$later->id]['id'] ?? null);
        $this->assertSame(InboxQuickReplyMatcher::VIA_EXAMPLE, $matches[$later->id]['via'] ?? null);

        Livewire::test(AdminInboxQuickReplies::class)
            ->call('deleteTag', $example->id)
            ->assertSet('statusMessage', 'Reply tag removed.')
            ->assertSee('No tags yet');

        $this->assertSame(0, InboxQuickReplyExample::query()->count());
    }

    #[Test]
    public function saving_replies_removes_tags_for_deleted_replies(): void
    {
        $this->actingAs($this->adminUser());

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'Keep', 'body' => 'Keep body'],
            ['label' => 'Drop', 'body' => 'Drop body'],
        ]);
        app(InboxQuickReplyMatcher::class)->addExample($replies[1]['id'], 'shippy when');

        $this->assertSame(1, InboxQuickReplyExample::query()->count());

        Livewire::test(AdminInboxQuickReplies::class)
            ->set('replies', [$replies[0]])
            ->call('save')
            ->assertSet('statusMessage', 'Quick replies saved.');

        $this->assertSame(0, InboxQuickReplyExample::query()->count());
    }

    #[Test]
    public function deleting_a_message_linked_tag_clears_the_message_tracking(): void
    {
        $this->actingAs($this->adminUser());

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
        ]);
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-tag-clear',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'dam koto?',
            'sent_at' => now(),
        ]);
        app(InboxQuickReplyMatcher::class)->tag($message, $replies[0]['id'], null);
        $example = InboxQuickReplyExample::query()->first();
        $this->assertSame($replies[0]['id'], $message->fresh()->quick_reply_id);

        Livewire::test(AdminInboxQuickReplies::class)
            ->call('deleteTag', $example->id)
            ->assertSet('statusMessage', 'Reply tag removed.');

        $this->assertNull($message->fresh()->quick_reply_id);
        $this->assertSame(0, InboxQuickReplyExample::query()->count());
    }
}
