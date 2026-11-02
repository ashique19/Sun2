<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxQuickReplySuggestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function saved_quick_replies_keep_stable_ids(): void
    {
        $this->actingAs($this->adminUser());

        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'Price', 'body' => 'দাম ১২০০'],
        ]);

        $this->assertNotSame('', $replies[0]['id']);
        $this->assertSame($replies[0]['id'], app(InboxQuickReplyService::class)->all()[0]['id']);
    }

    #[Test]
    public function quick_reply_example_index_names_fit_mysql_identifier_limit(): void
    {
        $names = collect(Schema::getIndexes('inbox_quick_reply_examples'))->pluck('name');

        $this->assertTrue($names->contains('iqre_qr_normalized_index'));

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen((string) $name), $name);
        }
    }

    #[Test]
    public function inbound_price_query_marks_the_bubble_and_fills_composer_without_sending(): void
    {
        Http::fake();
        $this->actingAs($this->adminUser());
        $this->storeReplies();
        $conversation = $this->conversation();
        $message = $this->inbound($conversation, 'dam koto?');

        $html = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-quick-reply-match="')
            ->assertSeeHtml('data-inbox-quick-reply-suggestion')
            ->assertSeeHtml('aria-label="Tag a quick reply"')
            ->assertSee('Price')
            ->html();

        $this->assertStringContainsString('ring-emerald-600', $html);
        $this->assertStringContainsString((string) $message->id, $html);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('insertQuickReplyById', app(InboxQuickReplyService::class)->all()[0]['id'])
            ->assertSet('replyText', 'দাম ১২০০ টাকা');

        Http::assertNothingSent();
    }

    #[Test]
    public function tagging_a_customer_message_paints_it_and_trains_later_typos(): void
    {
        Http::fake();
        $this->actingAs($this->adminUser());
        $replies = $this->storeReplies();
        $conversation = $this->conversation();
        $first = $this->inbound($conversation, 'eita kmn dam', now()->subMinute());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openQuickReplyTagMenu', $first->id)
            ->assertSet('taggingQuickReplyMessageId', $first->id)
            ->assertSeeHtml('data-inbox-quick-reply-tag-menu')
            ->call('tagMessageQuickReply', $first->id, $replies[0]['id'])
            ->assertSet('taggingQuickReplyMessageId', null)
            ->assertSeeHtml('data-inbox-quick-reply-tagged="1"');

        $this->assertSame($replies[0]['id'], $first->fresh()->quick_reply_id);
        $this->assertSame(1, InboxQuickReplyExample::query()->count());

        $later = $this->inbound($conversation, 'eita koto dam');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-quick-reply-match="'.$replies[0]['id'].'"');

        $this->assertNotNull($later->fresh());
        Http::assertNothingSent();
    }

    #[Test]
    public function price_plus_photo_boldens_priced_image(): void
    {
        $this->actingAs($this->adminUser());
        $this->storeReplies();
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK-QR',
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $this->inbound($conversation, 'eta koto', now()->subMinute());
        $photo = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/photo.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-price-query="1"')
            ->assertSeeHtml('data-inbox-priced-image-label')
            ->assertSeeHtml((string) $photo->id);
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function storeReplies(): array
    {
        $replies = app(InboxQuickReplyService::class)->save([
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
            ['label' => 'Delivery', 'body' => '৩-৫ দিনে ডেলিভারি'],
            ['label' => 'Order', 'body' => 'নাম ঠিকানা মোবাইল দিন'],
        ]);
        Setting::putValue(
            InboxQuickReplyService::SETTING_KEY,
            json_encode($replies, JSON_UNESCAPED_UNICODE),
            'channels',
        );

        return $replies;
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-qr-suggest',
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
    }

    private function inbound(ChannelConversation $conversation, string $body, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => $body,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}
