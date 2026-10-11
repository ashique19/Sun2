<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxImageMatchSpeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function catalog_comparisons_load_product_images_once(): void
    {
        $hasher = app(ProductImageHashService::class);

        $catalog = imagecreatetruecolor(80, 80);
        $red = imagecolorallocate($catalog, 200, 40, 60);
        $blue = imagecolorallocate($catalog, 40, 90, 200);
        for ($y = 0; $y < 80; $y++) {
            for ($x = 0; $x < 80; $x++) {
                imagesetpixel($catalog, $x, $y, ((int) ($x / 8) % 2) === 0 ? $red : $blue);
            }
        }
        ob_start();
        imagepng($catalog);
        $catalogBytes = (string) ob_get_clean();
        imagedestroy($catalog);

        $product = Product::query()->create([
            'name' => 'Indexed Once',
            'slug' => 'indexed-once-'.uniqid(),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/indexed-once.png',
            'alt' => $product->name,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
            'dct_hash' => $hasher->dctHashBinary($catalogBytes),
        ]);

        for ($i = 0; $i < 25; $i++) {
            $other = Product::query()->create([
                'name' => 'Filler '.$i,
                'slug' => 'filler-'.$i.'-'.uniqid(),
                'price' => 1000,
                'purchase_price' => 400,
                'stock_quantity' => 1,
                'is_published' => true,
            ]);
            ProductImage::query()->create([
                'product_id' => $other->id,
                'path' => '/img/products/filler-'.$i.'.png',
                'alt' => $other->name,
                'sort_order' => 0,
                'perceptual_hash' => sprintf('%016x', $i + 1),
                'perceptual_hashes' => [
                    ['hash' => sprintf('%016x', $i + 1), 'strategy' => 'full'],
                    ['hash' => sprintf('%016x', 1000 + $i), 'strategy' => 'center_0.7'],
                ],
                'dct_hash' => sprintf('%016x', 5000 + $i),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $match = $hasher->findBestAutoMatchFromBinary($catalogBytes);
        $firstLoads = $this->catalogHashLoads();

        $hasher->findBestAutoMatchFromBinary($catalogBytes);
        $secondLoads = $this->catalogHashLoads() - $firstLoads;

        DB::disableQueryLog();

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertSame(1, $firstLoads);
        $this->assertSame(0, $secondLoads);
    }

    #[Test]
    public function reopening_a_conversation_does_not_rematch_checked_photos(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-speed-'.uniqid(),
            'customer_name' => 'Speed Customer',
            'last_inbound_at' => now(),
        ]);

        $relativeDir = 'img/channel-inbound/'.$conversation->id;
        File::ensureDirectoryExists(public_path($relativeDir));
        $relativePath = $relativeDir.'/miss.jpg';
        $image = imagecreatetruecolor(32, 32);
        $color = imagecolorallocate($image, 20, 20, 20);
        imagefilledrectangle($image, 0, 0, 31, 31, $color);
        imagejpeg($image, public_path($relativePath), 80);
        imagedestroy($image);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/miss.jpg',
            'media_mime' => 'image/jpeg',
            'media_path' => $relativePath,
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('No match — search product');

        $checked = $message->fresh();
        $this->assertNotNull($checked->image_match_checked_at);
        $this->assertSame(ChannelMessageImageMatchService::STATUS_SKIPPED, $checked->image_match_meta['status'] ?? null);

        $this->mock(ProductImageMatcher::class)->shouldNotReceive('match');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('No match — search product')
            ->assertDontSee('Matching product…');

        $this->assertEquals($checked->image_match_checked_at, $message->fresh()->image_match_checked_at);
    }

    private function catalogHashLoads(): int
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'perceptual_hash'))
            ->count();
    }
}
