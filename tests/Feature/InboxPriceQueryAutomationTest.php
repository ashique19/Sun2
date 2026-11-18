<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductPricedImageService;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxPricePhotoRequestService;
use App\Services\Channels\InboxPriceQueryAutomationService;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxPriceQueryAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channels.inbox.auto_photo_request' => true,
            'channels.inbox.auto_priced_image' => false,
            'channels.inbox.auto_low_match_wait' => false,
            'facebook.messenger.enabled' => true,
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
        ]);
    }

    #[Test]
    public function no_image_sends_the_photo_request_copy(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_photo',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        $conversation = $this->conversation('psid-auto-none');
        $inbound = $this->inbound($conversation, 'dam koto?');

        app(InboxPriceQueryAutomationService::class)
            ->handlePriceAsk($conversation->fresh('messages'), $inbound);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame(InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY, $outbound->body);
        $this->assertTrue($outbound->raw_payload[InboxPricePhotoRequestService::RAW_FLAG] ?? false);
    }

    #[Test]
    public function no_image_does_not_send_when_photo_request_is_off(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
        ]);

        $conversation = $this->conversation('psid-auto-photo-off');
        $inbound = $this->inbound($conversation, 'dam koto?');

        app(InboxPriceQueryAutomationService::class)
            ->handlePriceAsk($conversation->fresh('messages'), $inbound);

        $this->assertSame(0, $this->outboundCount($conversation));
        Http::assertNothingSent();
    }

    #[Test]
    public function high_match_sends_priced_image_when_enabled(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_pimg',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => true,
        ]);

        $conversation = $this->conversation('psid-auto-pimg');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'এটা কত', now()->subMinutes(2));
        $photo = $this->inboundPhoto($conversation, $product, 91.0);

        $sent = app(InboxPriceQueryAutomationService::class)
            ->handleMatchedPhoto($conversation->fresh('messages'), $photo);

        $this->assertNotNull($sent);
        $this->assertNotNull($sent->media_url);
        $this->assertTrue($sent->raw_payload[InboxPriceQueryAutomationService::RAW_PRICED_IMAGE] ?? false);
        $this->assertSame($photo->id, $sent->reply_to_message_id);
        $this->assertSame(0, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('body', InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY)
            ->count());
    }

    #[Test]
    public function high_match_does_not_send_when_priced_image_is_off(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => false,
            InboxAutomationSettings::LOW_MATCH_WAIT => false,
        ]);

        $conversation = $this->conversation('psid-auto-pimg-off');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'dam koto?', now()->subMinutes(2));
        $photo = $this->inboundPhoto($conversation, $product, 91.0);

        $this->assertNull(
            app(InboxPriceQueryAutomationService::class)
                ->handleMatchedPhoto($conversation->fresh('messages'), $photo)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function low_match_sends_wait_text_when_enabled(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_wait',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => true,
        ]);

        $conversation = $this->conversation('psid-auto-wait');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'dam koto?', now()->subMinutes(2));
        $photo = $this->inboundPhoto($conversation, $product, 64.0, attached: false);

        $sent = app(InboxPriceQueryAutomationService::class)
            ->handleMatchedPhoto($conversation->fresh('messages'), $photo);

        $this->assertNotNull($sent);
        $this->assertSame(InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY, $sent->body);
        $this->assertTrue($sent->raw_payload[InboxPriceQueryAutomationService::RAW_LOW_MATCH_WAIT] ?? false);
        $this->assertSame($photo->id, $sent->reply_to_message_id);
        $this->assertNull($sent->media_url);
    }

    #[Test]
    public function low_match_does_not_send_when_wait_is_off(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => false,
        ]);

        $conversation = $this->conversation('psid-auto-wait-off');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'dam koto?', now()->subMinutes(2));
        $photo = $this->inboundPhoto($conversation, $product, 64.0, attached: false);

        $this->assertNull(
            app(InboxPriceQueryAutomationService::class)
                ->handleMatchedPhoto($conversation->fresh('messages'), $photo)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function match_at_eighty_percent_sends_priced_image(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_eighty',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => true,
        ]);

        $conversation = $this->conversation('psid-auto-eighty');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'দাম কত', now()->subMinutes(2));
        $photo = $this->inboundPhoto($conversation, $product, 80.0);

        $sent = app(InboxPriceQueryAutomationService::class)
            ->handleMatchedPhoto($conversation->fresh('messages'), $photo);

        $this->assertNotNull($sent);
        $this->assertTrue($sent->raw_payload[InboxPriceQueryAutomationService::RAW_PRICED_IMAGE] ?? false);
    }

    #[Test]
    public function tagged_which_product_reply_is_a_price_ask(): void
    {
        $replies = app(InboxQuickReplyService::class)->all();
        $which = collect($replies)->first(
            fn (array $reply): bool => str_contains($reply['label'], 'কোন প্রডাক্ট')
        );
        $this->assertNotNull($which);

        $conversation = $this->conversation('psid-auto-tag');
        $inbound = $this->inbound($conversation, 'eta dekhun');
        $inbound->forceFill(['quick_reply_id' => $which['id']])->save();

        $this->assertTrue(
            app(InboxPriceQueryAutomationService::class)->isPriceAsk($inbound->fresh())
        );
    }

    #[Test]
    public function photo_older_than_an_hour_does_not_count_as_session_image(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_old_photo',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => true,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => true,
        ]);

        $conversation = $this->conversation('psid-auto-old-photo');
        $product = $this->productWithPricedImage();
        $this->inboundPhoto($conversation, $product, 92.0, sentAt: now()->subHours(2));
        $inbound = $this->inbound($conversation, 'dam koto?');

        app(InboxPriceQueryAutomationService::class)
            ->handlePriceAsk($conversation->fresh('messages'), $inbound);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get();

        $this->assertCount(1, $outbound);
        $this->assertSame(InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY, $outbound->first()->body);
        $this->assertTrue($outbound->first()->raw_payload[InboxPricePhotoRequestService::RAW_FLAG] ?? false);
    }

    #[Test]
    public function price_ask_older_than_an_hour_does_not_auto_send_priced_image(): void
    {
        Http::fake();
        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => true,
        ]);

        $conversation = $this->conversation('psid-auto-old-ask');
        $product = $this->productWithPricedImage();
        $this->inbound($conversation, 'dam koto?', now()->subHours(2));
        $photo = $this->inboundPhoto($conversation, $product, 92.0);

        $this->assertNull(
            app(InboxPriceQueryAutomationService::class)
                ->handleMatchedPhoto($conversation->fresh('messages'), $photo)
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function photo_from_fifty_minutes_ago_still_pairs_with_a_price_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_fifty',
                'recipient_id' => 'psid-auto',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => false,
        ]);

        $conversation = $this->conversation('psid-auto-fifty');
        $product = $this->productWithPricedImage();
        $this->inboundPhoto($conversation, $product, 92.0, sentAt: now()->subMinutes(50));
        $inbound = $this->inbound($conversation, 'dam koto?');

        app(InboxPriceQueryAutomationService::class)
            ->handlePriceAsk($conversation->fresh('messages'), $inbound);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->first();

        $this->assertNotNull($outbound);
        $this->assertTrue($outbound->raw_payload[InboxPriceQueryAutomationService::RAW_PRICED_IMAGE] ?? false);
    }

    #[Test]
    public function greeting_is_not_a_price_ask(): void
    {
        $conversation = $this->conversation('psid-auto-hi');
        $inbound = $this->inbound($conversation, 'আসসালামু আলাইকুম');

        $this->assertFalse(
            app(InboxPriceQueryAutomationService::class)->isPriceAsk($inbound)
        );
    }

    #[Test]
    public function inbound_store_sends_priced_image_after_a_matched_photo_and_price_ask(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_auto_store',
                'recipient_id' => 'PSID_AUTO_STORE',
            ], 200),
        ]);

        app(InboxAutomationSettings::class)->save([
            InboxAutomationSettings::PHOTO_REQUEST => false,
            InboxAutomationSettings::PRICED_IMAGE => true,
            InboxAutomationSettings::LOW_MATCH_WAIT => false,
        ]);

        $conversation = $this->conversation('PSID_AUTO_STORE');
        $product = $this->productWithPricedImage();
        $this->inboundPhoto($conversation, $product, 92.0, sentAt: now()->subMinutes(3));

        $ask = app(ChannelConversationService::class)->storeMessage($conversation, [
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'dam koto?',
            'sent_at' => now(),
        ]);

        app(InboxPriceQueryAutomationService::class)
            ->handlePriceAsk($conversation->fresh('messages'), $ask);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get();

        $this->assertCount(1, $outbound);
        $this->assertTrue($outbound->first()->raw_payload[InboxPriceQueryAutomationService::RAW_PRICED_IMAGE] ?? false);
        $this->assertNotNull($outbound->first()->media_url);
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
        float $percent,
        bool $attached = true,
        mixed $sentAt = null,
    ): ChannelMessage {
        $score = round($percent / 100, 4);

        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/product-'.uniqid().'.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $attached ? $product->id : null,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'v' => 1,
                'status' => $attached
                    ? ChannelMessageImageMatchService::STATUS_MATCHED
                    : ChannelMessageImageMatchService::STATUS_SUGGESTED,
                'source' => 'visual',
                'candidates' => [[
                    'product_id' => $product->id,
                    'score' => $score,
                    'source' => 'visual',
                    'source_id' => 0,
                    'region' => null,
                ]],
            ],
            'sent_at' => $sentAt ?? now()->subMinute(),
        ]);
    }

    private function productWithPricedImage(): Product
    {
        $category = Category::query()->create([
            'name' => 'Rings',
            'slug' => 'rings-auto-'.uniqid(),
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Auto Ring',
            'slug' => 'auto-ring-'.uniqid(),
            'price' => 2500,
            'purchase_price' => 900,
            'stock_quantity' => 4,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'img0.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(240, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 140));
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        app(ProductPricedImageService::class)->generate($product);

        return $product->fresh(['images']);
    }

    private function outboundCount(ChannelConversation $conversation): int
    {
        return ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->count();
    }
}
