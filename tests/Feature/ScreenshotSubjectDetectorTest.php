<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ScreenshotSubjectDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScreenshotSubjectDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(200, 400);
        $black = imagecolorallocate($image, 10, 10, 12);
        $gold = imagecolorallocate($image, 196, 154, 72);
        imagefill($image, 0, 0, $black);
        imagefilledrectangle($image, 20, 80, 180, 240, $gold);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    #[Test]
    public function detect_crop_fractions_normalizes_gemini_json(): void
    {
        config([
            'gemini.api_key' => 'test-key',
            'channels.ai_draft.image_subject_detect' => true,
        ]);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')
            ->once()
            ->andReturn([
                'left' => 0.08,
                'top' => 0.18,
                'width' => 0.84,
                'height' => 0.42,
                'confidence' => 0.91,
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $detector = app(ScreenshotSubjectDetector::class);
        $suggestion = $detector->detectCropFractions($this->pngBytes(), 'image/png');

        $this->assertNotNull($suggestion);
        $this->assertSame('subject_vision', $suggestion['strategy']);
        $this->assertEqualsWithDelta(0.08, $suggestion['left'], 0.001);
        $this->assertEqualsWithDelta(0.18, $suggestion['top'], 0.001);
        $this->assertEqualsWithDelta(0.84, $suggestion['width'], 0.001);
        $this->assertEqualsWithDelta(0.42, $suggestion['height'], 0.001);
    }

    #[Test]
    public function detect_crop_fractions_returns_null_when_disabled_or_unconfigured(): void
    {
        config([
            'gemini.api_key' => null,
            'gemini.api_keys' => [],
            'channels.ai_draft.image_subject_detect' => false,
        ]);

        $this->assertNull(app(ScreenshotSubjectDetector::class)->detectCropFractions($this->pngBytes()));
    }

    #[Test]
    public function crop_suggestion_endpoint_uses_local_heuristics_only(): void
    {
        config([
            'channels.ai_draft.image_min_bytes' => 100,
            'channels.ai_draft.image_subject_detect' => true,
            'gemini.api_key' => 'test-key',
        ]);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')->never();
        $this->app->instance(GeminiClient::class, $gemini);

        $relativeDir = 'img/inbox-tests';
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        $relativePath = $relativeDir.'/subject-vision-'.uniqid().'.png';
        file_put_contents(public_path($relativePath), $this->pngBytes());

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-subject-1',
            'customer_name' => 'Subject Customer',
            'last_inbound_at' => now(),
        ]);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => '/'.$relativePath,
            'media_mime' => 'image/png',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser())
            ->getJson(route('admin.inbox.crop-suggestion', $message))
            ->assertOk();

        $suggestion = $response->json('suggestion');
        $this->assertNotNull($suggestion);
        $this->assertNotSame('subject_vision', $suggestion['strategy'] ?? null);

        @unlink(public_path($relativePath));
    }

    #[Test]
    public function subject_fractions_can_still_improve_hash_match_offline(): void
    {
        $hasher = app(ProductImageHashService::class);

        $catalog = imagecreatetruecolor(360, 360);
        $a = imagecolorallocate($catalog, 200, 40, 60);
        $b = imagecolorallocate($catalog, 40, 90, 200);
        for ($y = 0; $y < 360; $y++) {
            for ($x = 0; $x < 360; $x++) {
                imagesetpixel($catalog, $x, $y, (((int) ($x / 12)) % 2) === 0 ? $a : $b);
            }
        }
        ob_start();
        imagepng($catalog);
        $catalogBytes = (string) ob_get_clean();
        imagedestroy($catalog);

        $screenshot = imagecreatetruecolor(480, 960);
        $black = imagecolorallocate($screenshot, 8, 8, 10);
        imagefill($screenshot, 0, 0, $black);
        $catalogImage = imagecreatefromstring($catalogBytes);
        imagecopyresampled($screenshot, $catalogImage, 24, 120, 0, 0, 432, 432, 360, 360);
        imagedestroy($catalogImage);
        ob_start();
        imagepng($screenshot);
        $screenshotBytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        $product = Product::query()->create([
            'name' => 'Vision Match Necklace',
            'slug' => 'vision-match-necklace-'.uniqid(),
            'price' => 2500,
            'purchase_price' => 1000,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/missing/vision-match.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $subject = [
            'left' => 24 / 480,
            'top' => 120 / 960,
            'width' => 432 / 480,
            'height' => 432 / 960,
        ];

        $withSubject = $hasher->findBestAutoMatchFromBinary($screenshotBytes, $subject);

        $this->assertNotNull($withSubject);
        $this->assertSame($product->id, $withSubject['product_id']);
        $this->assertGreaterThanOrEqual(
            ProductImageHashService::AUTO_MATCH_PERCENT,
            $withSubject['match_percent'],
        );
    }
}
