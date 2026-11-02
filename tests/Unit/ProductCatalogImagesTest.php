<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductCatalogImagesTest extends TestCase
{
    #[Test]
    public function catalog_images_exclude_the_priced_stamp_and_blank_paths(): void
    {
        $product = new Product([
            'priced_image_path' => '/img/priced.jpg',
        ]);
        $product->setRelation('images', collect([
            new ProductImage(['path' => 'img/front.jpg']),
            new ProductImage(['path' => '/img/priced.jpg']),
            new ProductImage(['path' => '  ']),
            new ProductImage(['path' => 'img/side.jpg']),
        ]));

        $paths = $product->catalogImagesExcludingPriced()
            ->map(fn (ProductImage $image) => $image->path)
            ->all();

        $this->assertSame(['img/front.jpg', 'img/side.jpg'], $paths);
    }

    #[Test]
    public function catalog_images_keep_every_file_when_there_is_no_priced_stamp(): void
    {
        $product = new Product(['priced_image_path' => null]);
        $product->setRelation('images', collect([
            new ProductImage(['path' => 'img/a.jpg']),
            new ProductImage(['path' => 'img/b.jpg']),
        ]));

        $this->assertCount(2, $product->catalogImagesExcludingPriced());
    }
}
