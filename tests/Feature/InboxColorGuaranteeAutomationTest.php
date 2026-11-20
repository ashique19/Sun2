<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxColorGuaranteeAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxColorGuaranteeAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const CHIP_BODY = 'হ্যাঁ, কালার গ্যারান্টি আছে।';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_color_guarantee' => true,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);

        app(InboxQuickReplyService::class)->save([
            ['label' => InboxColorGuaranteeAutomationService::REPLY_LABEL, 'body' => self::CHIP_BODY],
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);
    }

    #[Test]
    public function ask_plus_pahari_product_sends_the_saved_reply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_color',
                'recipient_id' => 'psid-color',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-color');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');

        $sent = app(InboxColorGuaranteeAutomationService::class)
            ->reply($conversation->fresh(), $inbound);

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxColorGuaranteeAutomationService::RAW_FLAG] ?? false);
        $this->assertSame($inbound->id, $sent->reply_to_message_id);
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function ask_plus_other_category_does_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-color-other');
        $product = $this->productInCategory('Rings');
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function ask_without_a_product_photo_does_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-color-none');
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function pahari_photo_without_a_color_ask_does_not_send(): void
    {
        Http::fake();
        $conversation = $this->conversation('psid-color-photo-only');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $photo = $this->inboundPhoto($conversation, $product);

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->replyAfterMatchedPhoto((int) $photo->id)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function pahari_photo_plus_a_price_ask_does_not_send_color_guarantee(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            [
                'label' => InboxColorGuaranteeAutomationService::REPLY_LABEL,
                'body' => 'কালার গ্যারান্টি ২ বছর। প্রয়োজনে রূপার দোকান থেকে আবার কালার করতে পারবেন।',
            ],
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টটা দেখতে চান?'],
        ]);

        $conversation = $this->conversation('psid-color-price-ask');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'price');

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function matched_pahari_photo_after_an_ask_sends_the_saved_reply(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_color_photo',
                'recipient_id' => 'psid-color-after',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-color-after');
        $this->inbound($conversation, 'কালার গ্যারান্টি আছে?', now()->subMinutes(2));
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $photo = $this->inboundPhoto($conversation, $product);

        $sent = app(InboxColorGuaranteeAutomationService::class)
            ->replyAfterMatchedPhoto((int) $photo->id);

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxColorGuaranteeAutomationService::RAW_FLAG] ?? false);
    }

    #[Test]
    public function tagged_chip_sends_when_the_session_product_is_pahari(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_color_tag',
                'recipient_id' => 'psid-color-tag',
            ], 200),
        ]);

        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isColorGuaranteeReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-color-tag');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'এটার রং থাকবে?');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $sent = app(InboxColorGuaranteeAutomationService::class)
            ->reply($conversation->fresh(), $inbound->fresh());

        $this->assertNotNull($sent);
        $this->assertSame(self::CHIP_BODY, $sent->body);
    }

    #[Test]
    public function tagged_chip_does_not_send_for_another_category(): void
    {
        Http::fake();
        $chip = collect(app(InboxQuickReplyService::class)->all())
            ->first(fn (array $reply): bool => app(InboxQuickReplyMatcher::class)->isColorGuaranteeReply($reply));
        $this->assertNotNull($chip);

        $conversation = $this->conversation('psid-color-tag-other');
        $product = $this->productInCategory('Rings');
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');
        $inbound->forceFill(['quick_reply_id' => $chip['id']])->save();

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound->fresh())
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function repeated_asks_in_the_session_send_once(): void
    {
        Http::fake(function () {
            return Http::response([
                'message_id' => 'm_auto_color_once_'.uniqid('', true),
                'recipient_id' => 'psid-color-once',
            ], 200);
        });

        $conversation = $this->conversation('psid-color-once');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product, sentAt: now()->subMinutes(4));
        $first = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?', now()->subSeconds(8));
        $service = app(InboxColorGuaranteeAutomationService::class);
        $this->assertNotNull($service->reply($conversation->fresh(), $first));

        $second = $this->inbound($conversation, 'কালার গ্যারান্টি?');
        $this->assertNull($service->reply($conversation->fresh(), $second));

        $this->assertSame(1, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->count());
        $this->assertSame(1, Http::recorded()->count());
    }

    #[Test]
    public function no_chip_does_not_send(): void
    {
        Http::fake();
        app(InboxQuickReplyService::class)->save([
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
        ]);

        $conversation = $this->conversation('psid-color-no-chip');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function disabled_setting_does_not_send(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::COLOR_GUARANTEE => false,
        ]);

        $conversation = $this->conversation('psid-color-off');
        $product = $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME);
        $this->inboundPhoto($conversation, $product);
        $inbound = $this->inbound($conversation, 'কালার গ্যারান্টি আছে?');

        $this->assertNull(
            app(InboxColorGuaranteeAutomationService::class)->reply($conversation->fresh(), $inbound)
        );
        Http::assertNothingSent();
    }

    private function conversation(string $psid): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
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

    private function inboundPhoto(
        ChannelConversation $conversation,
        Product $product,
        mixed $sentAt = null,
    ): ChannelMessage {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/product-'.uniqid().'.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'v' => 1,
                'status' => ChannelMessageImageMatchService::STATUS_MATCHED,
                'source' => 'visual',
                'candidates' => [[
                    'product_id' => $product->id,
                    'score' => 0.93,
                    'source' => 'visual',
                    'source_id' => 0,
                    'region' => null,
                ]],
            ],
            'sent_at' => $sentAt ?? now()->subMinute(),
        ]);
    }

    private function productInCategory(string $name): Product
    {
        $category = Category::query()->create([
            'name' => $name,
            'slug' => 'cat-color-'.uniqid(),
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Color '.$name,
            'slug' => 'color-prod-'.uniqid(),
            'price' => 1800,
            'purchase_price' => 700,
            'stock_quantity' => 3,
            'is_published' => true,
        ]);
    }
}
