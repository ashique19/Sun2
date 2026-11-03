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
    public function price_query_does_not_suggest_which_product_after_customer_photos(): void
    {
        Http::fake();
        $this->actingAs($this->adminUser());
        $replies = $this->storeReplies([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টের দাম জানতে চাইলে বলুন কোনটা'],
            ['label' => 'Delivery', 'body' => '৩-৫ দিনে ডেলিভারি'],
        ]);
        $conversation = $this->conversation('psid-qr-which-photo');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'দাম কত');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertDontSeeHtml('data-inbox-quick-reply-match="'.$replies[0]['id'].'"')
            ->assertDontSeeHtml('data-inbox-quick-reply-suggestion');

        $this->assertNotNull($message->fresh());
    }

    #[Test]
    public function price_query_without_photos_still_suggests_which_product(): void
    {
        Http::fake();
        $this->actingAs($this->adminUser());
        $replies = $this->storeReplies([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টের দাম জানতে চাইলে বলুন কোনটা'],
        ]);
        $conversation = $this->conversation('psid-qr-which-plain');
        $this->inbound($conversation, 'দাম কত');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-quick-reply-match="'.$replies[0]['id'].'"')
            ->assertSeeHtml('data-inbox-quick-reply-suggestion')
            ->assertSee('কোন প্রডাক্ট?');
    }

    #[Test]
    public function price_query_offers_send_all_priced_images_above_84(): void
    {
        $this->fakeMessenger();
        $this->actingAs($this->adminUser());
        $this->storeReplies();
        $conversation = $this->conversation('psid-qr-bulk-send');
        $high = $this->productWithPricedImage('High Match Necklace');
        $low = $this->productWithPricedImage('Low Match Earring');
        $keep = $this->inboundMatchedPhoto($conversation, $high, 0.91, now()->subMinutes(2));
        $this->inboundMatchedPhoto($conversation, $low, 0.80, now()->subMinute());
        $this->inbound($conversation, 'এটা কত');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-priced-image-send-all')
            ->assertSeeHtml('Send priced images')
            ->assertSeeHtml('data-inbox-priced-image-targets')
            ->assertSeeHtml('"message_id":'.$keep->id)
            ->assertDontSeeHtml('"product_id":'.$low->id)
            ->call('sendUnrepliedPricedImages')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Priced image sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get();

        $this->assertCount(1, $outbound);
        $this->assertSame($keep->id, $outbound->first()->reply_to_message_id);
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
     * @param  list<array{label: string, body: string}>|null  $rows
     * @return list<array{id: string, label: string, body: string}>
     */
    private function storeReplies(?array $rows = null): array
    {
        $replies = app(InboxQuickReplyService::class)->save($rows ?? [
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

    private function conversation(string $psid = 'psid-qr-suggest'): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
    }

    private function inboundPhoto(ChannelConversation $conversation, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/product-'.uniqid().'.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function inboundMatchedPhoto(
        ChannelConversation $conversation,
        Product $product,
        float $score,
        mixed $sentAt = null,
    ): ChannelMessage {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/matched-'.$product->id.'.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'status' => 'matched',
                'candidates' => [
                    ['product_id' => $product->id, 'score' => $score, 'source' => 'catalog'],
                ],
            ],
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function productWithPricedImage(string $name): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'sku' => 'SKU'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.'priced.jpg';
        $image = imagecreatetruecolor(240, 240);
        $fill = imagecolorallocate($image, 180, 90, 40);
        imagefill($image, 0, 0, $fill);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        $product->forceFill([
            'priced_image_path' => $relativeDir.'/priced.jpg',
        ])->save();

        return $product->fresh();
    }

    private function fakeMessenger(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        $messageIdIndex = 0;
        Http::fake(function () use (&$messageIdIndex) {
            $messageIdIndex++;

            return Http::response([
                'message_id' => 'm_priced_bulk_'.$messageIdIndex,
                'recipient_id' => 'psid-qr-bulk-send',
            ], 200);
        });
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
