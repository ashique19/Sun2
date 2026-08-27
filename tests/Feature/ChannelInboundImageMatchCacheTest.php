<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageHashService;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelInboundMediaService;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Channels\ChannelOrderParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelInboundImageMatchCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function inbound_media_is_persisted_and_hashed_once(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        $bytes = $this->jpegBytes([180, 40, 40]);
        Http::fake([
            'https://lookaside.fbsbx.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $conversation = $this->conversation('PSID_CACHE_1');
        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $first = app(ChannelInboundMediaService::class)->ensureCached($message->fresh());
        $this->assertNotNull($first);
        $this->assertNotNull($message->fresh()->media_path);
        $this->assertNotNull($message->fresh()->media_dhash);
        $this->assertNotNull($message->fresh()->media_dct_hash);
        $this->assertFileExists(public_path($message->fresh()->media_path));

        Http::fake(); // any further HTTP would fail the test if called
        $second = app(ChannelInboundMediaService::class)->ensureCached($message->fresh());
        $this->assertNotNull($second);
        $this->assertSame($first['dhash'], $second['dhash']);
        $this->assertSame($message->fresh()->media_path, $second['path']);
    }

    #[Test]
    public function fifty_image_conversation_does_not_re_download_cached_photos_on_second_parse(): void
    {
        config([
            'gemini.api_key' => null,
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
            'channels.ai_draft.max_inbound_messages' => 60,
            'channels.ai_draft.lookback_hours' => 72,
        ]);

        $product = $this->product(['name' => 'Album Kurti']);
        $bytes = $this->jpegBytes([40, 180, 80]);
        $hash = app(ProductImageHashService::class)->hashBinary($bytes);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/products/album-kurti.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
            'dct_hash' => app(ProductImageHashService::class)->dctHashBinary($bytes),
        ]);

        $downloadCount = 0;
        Http::fake(function ($request) use (&$downloadCount, $bytes) {
            if (str_contains($request->url(), 'lookaside.fbsbx.com')) {
                $downloadCount++;

                return Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('not found', 404);
        });

        $conversation = $this->conversation('PSID_ALBUM_50');
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_text',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Buyer\n01627237432\nBanani, Dhaka",
            'sent_at' => now()->subMinutes(60),
        ]);

        for ($i = 0; $i < 50; $i++) {
            app(ChannelConversationService::class)->storeMessage($conversation, [
                'external_message_id' => 'm_img_'.$i,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id='.$i,
                'media_mime' => 'image/jpeg',
                'sent_at' => now()->subMinutes(50 - $i),
            ]);
        }

        $draft = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertNotNull($draft);
        $this->assertSame($product->id, (int) $draft->items->first()->product_id);
        $firstDownloads = $downloadCount;
        $this->assertGreaterThan(0, $firstDownloads);

        // Second parse must use media_path / cached hashes — zero new CDN downloads.
        $downloadCount = 0;
        $again = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertNotNull($again);
        $this->assertSame(0, $downloadCount, 'Cached inbound media must not re-download on re-parse');
        $this->assertTrue(
            $conversation->messages()
                ->whereNotNull('media_url')
                ->whereNotNull('media_path')
                ->whereNotNull('media_dhash')
                ->exists()
        );
    }

    #[Test]
    public function gemini_gap_fill_receives_text_only_never_inline_image_data(): void
    {
        $product = $this->product(['name' => 'Text Only Kurti']);
        $capturedParts = null;

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')
            ->once()
            ->andReturnUsing(function (string $system, array $parts) use (&$capturedParts, $product) {
                $capturedParts = $parts;

                return [
                    'name' => 'Sajida',
                    'phone' => '01712345678',
                    'address' => 'Road 4, Gulshan',
                    'city' => 'Dhaka',
                    'area' => 'Gulshan',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => 1,
                ];
            });
        $this->app->instance(GeminiClient::class, $gemini);

        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        $bytes = $this->jpegBytes([90, 90, 200]);
        Http::fake([
            'https://lookaside.fbsbx.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $conversation = $this->conversation('PSID_GEMINI_TEXT');
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_gem_text',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "01712345678\nplease deliver",
            'sent_at' => now()->subMinute(),
        ]);
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_gem_img',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=gem',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertNotNull($draft);
        $this->assertNotNull($capturedParts);
        $this->assertCount(1, $capturedParts);
        $this->assertArrayHasKey('text', $capturedParts[0]);
        $this->assertArrayNotHasKey('inline_data', $capturedParts[0]);
        foreach ($capturedParts as $part) {
            $this->assertArrayNotHasKey('inline_data', $part);
        }
    }

    #[Test]
    public function corrupt_image_does_not_block_text_fields_or_other_matches(): void
    {
        config([
            'gemini.api_key' => null,
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        $product = $this->product(['name' => 'Good Match Kurti']);
        $goodBytes = $this->jpegBytes([10, 200, 10]);
        $hash = app(ProductImageHashService::class)->hashBinary($goodBytes);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/products/good-match.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
        ]);

        Http::fake([
            'https://lookaside.fbsbx.com/*corrupt*' => Http::response('not-an-image', 200, ['Content-Type' => 'image/jpeg']),
            'https://lookaside.fbsbx.com/*good*' => Http::response($goodBytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $conversation = $this->conversation('PSID_CORRUPT');
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_ok_text',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Nila\n01627237432\nBanani, Dhaka",
            'sent_at' => now()->subMinutes(2),
        ]);
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_bad',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=corrupt',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);
        app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_good',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=good',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftService::class)
            ->syncDraftFromConversation($conversation->fresh(['messages']));

        $this->assertNotNull($draft);
        $this->assertSame('Nila', $draft->name);
        $this->assertSame('01627237432', $draft->phone);
        $this->assertSame($product->id, (int) $draft->items->first()->product_id);
    }

    #[Test]
    public function parser_skips_gemini_entirely_for_image_only_threads_without_phone(): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->never();
        $gemini->shouldReceive('generateJsonFromParts')->never();
        $this->app->instance(GeminiClient::class, $gemini);

        $conversation = $this->conversation('PSID_IMG_ONLY');
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/x.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));
        $this->assertNull($parsed['phone']);
        $this->assertContains('phone_missing', $parsed['weak_points']);
    }

    private function conversation(string $psid): ChannelConversation
    {
        return app(ChannelConversationService::class)->findOrCreate(
            ChannelConversation::CHANNEL_MESSENGER,
            $psid,
            ['customer_name' => 'Cache Buyer'],
        );
    }

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Cache Product',
            'slug' => 'cache-product-'.uniqid(),
            'sku' => 'CP'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 25,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array{0:int,1:int,2:int}  $color
     */
    private function jpegBytes(array $color): string
    {
        $image = imagecreatetruecolor(64, 64);
        $paint = imagecolorallocate($image, $color[0], $color[1], $color[2]);
        imagefilledrectangle($image, 0, 0, 63, 63, $paint);
        $accent = imagecolorallocate($image, 255 - $color[0], 255 - $color[1], 255 - $color[2]);
        imagefilledellipse($image, 32, 32, 24, 24, $accent);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
