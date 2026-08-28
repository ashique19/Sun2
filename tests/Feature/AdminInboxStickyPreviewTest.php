<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxStickyPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(string $psid, ?Carbon $lastInbound = null): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Customer '.$psid,
            'last_inbound_at' => $lastInbound ?? now(),
        ]);
    }

    #[Test]
    public function desktop_preview_stays_on_first_seen_thread_when_newer_arrives(): void
    {
        $this->actingAs($this->adminUser());

        $older = $this->conversation('psid-old', now()->subMinutes(10));
        ChannelMessage::query()->create([
            'channel_conversation_id' => $older->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Older hello',
            'sent_at' => now()->subMinutes(10),
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->assertSet('selectedConversationId', null)
            ->assertSet('desktopPreviewConversationId', $older->id)
            ->assertSee('Older hello');

        $newer = $this->conversation('psid-new', now());
        ChannelMessage::query()->create([
            'channel_conversation_id' => $newer->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Brand new hello',
            'sent_at' => now(),
        ]);

        // Re-render (poll-like) must keep sticky preview on the first thread.
        $component->call('$refresh')
            ->assertSet('desktopPreviewConversationId', $older->id)
            ->assertSee('Older hello')
            ->assertSee('Brand new hello'); // listed in the left pane
    }

    #[Test]
    public function inbox_page_uses_full_height_flex_layout(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.inbox'))
            ->assertOk()
            ->assertSeeHtml('class="flex min-h-0 flex-1 flex-col xl:gap-4"')
            ->assertSeeHtml('grid-rows-1');
    }

    #[Test]
    public function selecting_a_conversation_updates_sticky_preview(): void
    {
        $this->actingAs($this->adminUser());

        $first = $this->conversation('psid-a', now()->subMinute());
        $second = $this->conversation('psid-b', now());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $first->id)
            ->assertSet('desktopPreviewConversationId', $first->id)
            ->call('selectConversation', $second->id)
            ->assertSet('desktopPreviewConversationId', $second->id)
            ->assertSet('selectedConversationId', $second->id);
    }

    #[Test]
    public function inbox_page_keeps_reply_composer_in_dom(): void
    {
        $this->actingAs($this->adminUser());

        $conversation = $this->conversation('psid-reply', now());
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Need this product',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Message…')
            ->assertSeeHtml('wire:keydown.enter.exact.prevent="sendReply"')
            ->assertSeeHtml('aria-label="Send"')
            ->assertSeeHtml('absolute right-1.5 top-1/2')
            ->assertSeeHtml('items-center gap-1.5')
            ->assertSeeHtml('data-inbox-thread-header')
            ->assertSeeHtml('flex min-h-0 flex-1 flex-col overflow-hidden');
    }

    public function inbox_mobile_thread_sheet_uses_viewport_aware_positioning(): void
    {
        $this->actingAs($this->adminUser());

        $conversation = $this->conversation('psid-mobile-layout', now());

        $this->get(route('admin.inbox', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSeeHtml('data-inbox-mobile-thread-sheet')
            ->assertSeeHtml('data-inbox-mobile-thread="1"');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSet('mobileThreadOpen', true)
            ->assertSeeHtml('x-effect="document.body.dataset.inboxMobileThread = $wire.mobileThreadOpen');
    }

    #[Test]
    public function inbox_errors_render_as_fixed_toasts_not_in_flow_banners(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminInbox::class)
            ->set('error', 'Could not send reply.')
            ->assertSeeHtml('data-inbox-error-toast')
            ->assertSee('Could not send reply.')
            ->assertDontSeeHtml('mx-4 mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900')
            ->call('clearInboxError')
            ->assertSet('error', null);
    }
}
