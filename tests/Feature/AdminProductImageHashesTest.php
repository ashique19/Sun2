<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductImageHashes;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageHashesTest extends TestCase
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
    public function image_hashes_page_shows_backfill_banner_for_legacy_rows(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Legacy Hash Product',
            'slug' => 'legacy-banner-'.uniqid(),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 1,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/'.$product->id.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => str_repeat('a', 16),
            'perceptual_hashes' => [['strategy' => 'full', 'hash' => str_repeat('a', 16)]],
            'dct_hash' => null,
            'embedding_vector' => null,
        ]);

        $this->get(route('admin.image-hashes'))
            ->assertOk()
            ->assertSee('Screenshot matching needs a catalog backfill')
            ->assertSee('Rebuild image hashes');
    }

    #[Test]
    public function rebuild_modal_backfills_legacy_row_without_force(): void
    {
        $this->actingAs($this->adminUser());

        $hasher = app(ProductImageHashService::class);

        $product = Product::query()->create([
            'name' => 'Modal Backfill Product',
            'slug' => 'modal-backfill-'.uniqid(),
            'price' => 1500,
            'purchase_price' => 600,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $absolute = $absoluteDir.'/catalog.jpg';
        $image = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($image, 120, 80, 200);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        $legacyHash = $hasher->hashFile($absolute);

        $row = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog.jpg',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $legacyHash,
            'perceptual_hashes' => [['strategy' => 'full', 'hash' => $legacyHash]],
            'dct_hash' => null,
            'embedding_vector' => null,
        ]);

        Livewire::test(AdminProductImageHashes::class)
            ->call('openRebuildModal')
            ->assertSet('rebuildModalOpen', true)
            ->assertSet('forceRehash', false)
            ->assertSee('Backfill missing')
            ->call('confirmRebuild')
            ->assertSet('rebuildModalOpen', false);

        $row->refresh();

        $this->assertNotNull($row->dct_hash);
        $this->assertIsArray($row->embedding_vector);
        $this->assertNotEmpty($row->embedding_vector);
    }
}
