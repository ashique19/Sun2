<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

class ChannelMessageScreenshotMatchTest extends TestCase
{
    use ProductPhotoFixtures;
    use RefreshDatabase;

    #[Test]
    public function screenshot_auto_match_uses_fully_indexed_catalog_hashes(): void
    {
        config(['channels.ai_draft.image_min_bytes' => 100]);

        $catalogBytes = $this->productPhotoBytes(61);
        $screenshotBytes = $this->phoneScreenshotBytes($catalogBytes);

        $product = Product::query()->create([
            'name' => 'Indexed Screenshot Product',
            'slug' => 'indexed-screenshot-'.uniqid(),
            'price' => 2200,
            'purchase_price' => 900,
            'stock_quantity' => 3,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        file_put_contents($absoluteDir.'/catalog.jpg', $catalogBytes);

        $imageRow = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        app(ProductImageHashService::class)->storeHash($imageRow);
        $imageRow->refresh();
        $this->assertNotNull($imageRow->dct_hash);
        $this->assertNotNull($imageRow->embedding_vector);
        $this->assertNotNull($imageRow->match_signature);

        $customerUrl = 'https://cdn.example.test/screenshot-indexed.png';
        Http::fake([
            $customerUrl => Http::response($screenshotBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-screenshot-indexed',
            'customer_name' => 'Screenshot Customer',
            'last_inbound_at' => now(),
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/png',
            'sent_at' => now(),
        ]);

        $match = app(ChannelMessageImageMatchService::class)->bestAutoMatch($message);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertSame('visual', $match['strategy']);
        $this->assertGreaterThanOrEqual(ProductImageMatcher::autoScore() * 100, $match['match_percent']);

        @unlink($absoluteDir.'/catalog.jpg');
    }
}
