<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductCollageTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Product, 1: list<ProductImage>}
     */
    private function productWithImages(int $count = 3): array
    {
        $product = Product::query()->create([
            'name' => 'Collage Ring',
            'slug' => 'collage-ring',
            'price' => 1800,
            'is_published' => true,
        ]);

        $images = [];

        for ($i = 1; $i <= $count; $i++) {
            $images[] = app(ProductImageService::class)->store(
                $product,
                UploadedFile::fake()->image("ring-{$i}.jpg", 400 + ($i * 20), 400),
                "Ring {$i}",
            );
        }

        return [$product->fresh(['images']), $images];
    }

    #[Test]
    public function product_edit_exposes_collage_selection_and_modal_shell(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImages(3);

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product])->html();

        $this->assertStringContainsString('data-make-collage', $html);
        $this->assertStringContainsString('data-collage-select', $html);
        $this->assertStringContainsString('toggleCollageImage(', $html);
        $this->assertStringContainsString('openCollage()', $html);
        $this->assertStringContainsString('data-collage-modal', $html);
        $this->assertStringContainsString('data-collage-canvas', $html);
        $this->assertStringContainsString('data-collage-patterns', $html);
        $this->assertStringContainsString('data-collage-save', $html);
        $this->assertStringContainsString('Save as product image', $html);
        $this->assertStringContainsString('Select 2 or more, then Make collage.', $html);

        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString('COLLAGE_PATTERNS', $source);
        $this->assertStringContainsString("id: 'split-h'", $source);
        $this->assertStringContainsString("id: 'trio-left'", $source);
        $this->assertStringContainsString("id: 'quad'", $source);
        $this->assertStringContainsString('saveCollageImage(base64, \'image/jpeg\')', $source);
        $this->assertStringContainsString('startCollagePan', $source);
        $this->assertStringContainsString('startCollageZoom', $source);
        $this->assertStringContainsString('composeCollageCanvas', $source);
    }

    #[Test]
    public function single_image_product_does_not_offer_collage(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImages(1);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertDontSeeHtml('data-make-collage')
            ->assertDontSeeHtml('Select 2 or more, then Make collage.');
    }

    #[Test]
    public function save_collage_image_adds_a_new_gallery_row(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImages(2);

        $jpeg = imagecreatetruecolor(800, 800);
        $left = imagecolorallocate($jpeg, 40, 90, 160);
        $right = imagecolorallocate($jpeg, 200, 80, 40);
        imagefilledrectangle($jpeg, 0, 0, 399, 799, $left);
        imagefilledrectangle($jpeg, 400, 0, 799, 799, $right);
        ob_start();
        imagejpeg($jpeg, null, 85);
        imagedestroy($jpeg);
        $binary = ob_get_clean();

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('saveCollageImage', base64_encode($binary), 'image/jpeg')
            ->assertHasNoErrors()
            ->assertSet('message', 'Collage saved as a product image.')
            ->assertSet('imagesEpoch', 1);

        $this->assertSame(3, ProductImage::query()->where('product_id', $product->id)->count());

        $collage = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($collage);
        $this->assertSame('Collage Ring collage', $collage->alt);
        $this->assertFalse($collage->is_primary);
        $this->assertFalse($collage->is_admin_only);
        $this->assertFileExists(public_path(ltrim($collage->path, '/')));

        $returned = $component->effects['returns'][0] ?? null;
        $this->assertIsArray($returned);
        $this->assertSame($collage->id, $returned['id']);
        $this->assertSame($collage->path, $returned['path']);
        $this->assertStringContainsString('/images/'.$collage->id.'/raw', $returned['url']);
    }

    #[Test]
    public function save_collage_image_requires_image_payload(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImages(2);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('saveCollageImage')
            ->assertHasErrors(['collageImage']);

        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
    }
}
