<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Admin\ProductImageMatchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

class ProductImageVisualMatcherTest extends TestCase
{
    use ProductPhotoFixtures;
    use RefreshDatabase;

    private function seedCatalog(int $count = 6): array
    {
        $products = [];
        for ($seed = 1; $seed <= $count; $seed++) {
            $products[$seed] = $this->catalogProduct('Catalog Item '.$seed, $this->productPhotoBytes($seed));
        }

        return $products;
    }

    #[Test]
    public function identical_photo_is_a_confident_match(): void
    {
        $products = $this->seedCatalog();

        $result = app(ProductImageMatcher::class)->match($this->productPhotoBytes(3));

        $this->assertSame($products[3]->id, $result['candidates'][0]['product_id']);
        $this->assertGreaterThanOrEqual(0.95, $result['candidates'][0]['score']);
        $this->assertTrue(ProductImageMatcher::isConfident($result['candidates']));
    }

    #[Test]
    public function phone_screenshot_matches_the_photo_region_not_the_ui_chrome(): void
    {
        $products = $this->seedCatalog();

        $result = app(ProductImageMatcher::class)->match($this->phoneScreenshotBytes($this->productPhotoBytes(4)));

        $this->assertSame($products[4]->id, $result['candidates'][0]['product_id']);
        $this->assertTrue(ProductImageMatcher::isConfident($result['candidates']));
        $this->assertLessThan(0.7, $result['region']['height'], 'Expected the matched region to exclude header/caption chrome.');
    }

    #[Test]
    public function customer_crop_of_a_catalog_photo_is_ranked_first(): void
    {
        $products = $this->seedCatalog();

        $result = app(ProductImageMatcher::class)->match($this->croppedPhotoBytes($this->productPhotoBytes(5), 0.72));

        $this->assertSame($products[5]->id, $result['candidates'][0]['product_id']);
        $this->assertGreaterThanOrEqual(ProductImageMatcher::suggestScore(), $result['candidates'][0]['score']);
    }

    #[Test]
    public function unrelated_photo_is_never_confident(): void
    {
        $this->seedCatalog();

        $result = app(ProductImageMatcher::class)->match($this->productPhotoBytes(99));

        $this->assertFalse(ProductImageMatcher::isConfident($result['candidates']));
        $this->assertLessThan(ProductImageMatcher::autoScore(), $result['candidates'][0]['score'] ?? 0.0);
    }

    #[Test]
    public function near_identical_catalog_twins_are_not_auto_attached(): void
    {
        $photo = $this->productPhotoBytes(7);
        $this->catalogProduct('Twin Gold', $photo);
        $this->catalogProduct('Twin Gold Copy', $photo);

        $result = app(ProductImageMatcher::class)->match($photo);

        $this->assertGreaterThanOrEqual(ProductImageMatcher::autoScore(), $result['candidates'][0]['score']);
        $this->assertFalse(ProductImageMatcher::isConfident($result['candidates']), 'Two equally strong products need staff review.');
    }

    #[Test]
    public function region_limits_the_search_to_a_staff_crop(): void
    {
        $products = $this->seedCatalog();
        $screenshot = $this->phoneScreenshotBytes($this->productPhotoBytes(2));

        $result = app(ProductImageMatcher::class)->match($screenshot, [
            'left' => 0.0,
            'top' => 160 / 780,
            'width' => 1.0,
            'height' => 360 / 780,
        ]);

        $this->assertSame(1, $result['proposals']);
        $this->assertSame($products[2]->id, $result['candidates'][0]['product_id']);
        $this->assertGreaterThanOrEqual(0.95, $result['candidates'][0]['score']);
    }

    #[Test]
    public function new_catalog_photos_are_picked_up_without_a_manual_reindex(): void
    {
        $this->seedCatalog(3);
        $matcher = app(ProductImageMatcher::class);
        $matcher->match($this->productPhotoBytes(1));

        $added = $this->catalogProduct('Added Later', $this->productPhotoBytes(42));
        $result = $matcher->match($this->productPhotoBytes(42));

        $this->assertSame($added->id, $result['candidates'][0]['product_id']);
    }

    #[Test]
    public function unsigned_catalog_photos_are_signed_lazily_within_the_budget(): void
    {
        $product = $this->catalogProduct('Legacy Indexed', $this->productPhotoBytes(11), signed: false);
        $missing = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/img/products/'.$product->id.'/gone.jpg',
            'sort_order' => 1,
        ]);

        $result = app(ProductImageMatcher::class)->match($this->productPhotoBytes(11));

        $this->assertSame($product->id, $result['candidates'][0]['product_id']);
        $this->assertNotNull($product->images()->first()->match_signature);
        $this->assertNull($missing->fresh()->match_signature);

        $this->assertSame(0, app(ProductImageMatchIndex::class)->backfillMissingSignatures(), 'Unreadable images are not retried on every match.');
    }

    #[Test]
    public function lazy_signing_can_be_disabled(): void
    {
        config(['channels.image_match.lazy_index_budget_ms' => 0]);
        $this->catalogProduct('Legacy Unsigned', $this->productPhotoBytes(12), signed: false);

        $result = app(ProductImageMatcher::class)->match($this->productPhotoBytes(12));

        $this->assertSame([], $result['candidates']);
    }
}
