<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageFileTest extends TestCase
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
    public function staff_can_fetch_product_image_bytes_for_editor(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Raw File',
            'slug' => 'raw-file',
            'price' => 900,
            'is_published' => true,
        ]);

        $image = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 640, 480),
        );

        $response = $this->get(route('admin.products.images.raw', [$product, $image]));

        $response->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertNotSame('', $response->getContent());
    }

    #[Test]
    public function raw_image_route_rejects_foreign_image_ids(): void
    {
        $this->actingAs($this->adminUser());

        $productA = Product::query()->create([
            'name' => 'A',
            'slug' => 'a-product',
            'price' => 100,
            'is_published' => true,
        ]);
        $productB = Product::query()->create([
            'name' => 'B',
            'slug' => 'b-product',
            'price' => 100,
            'is_published' => true,
        ]);

        $imageB = ProductImage::query()->create([
            'product_id' => $productB->id,
            'path' => '/img/products/'.$productB->id.'/missing_lg.jpg',
            'alt' => 'B',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->get(route('admin.products.images.raw', [$productA, $imageB]))
            ->assertNotFound();
    }
}
