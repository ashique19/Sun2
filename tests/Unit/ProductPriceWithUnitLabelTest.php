<?php

namespace Tests\Unit;

use App\Models\Product;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductPriceWithUnitLabelTest extends TestCase
{
    #[Test]
    public function it_formats_bangla_price_with_default_unit(): void
    {
        $product = new Product([
            'price' => 3200,
        ]);

        $this->assertSame('৳ ৩,২০০/পিস', $product->priceWithUnitLabel());
    }

    #[Test]
    public function it_includes_custom_price_unit(): void
    {
        $product = new Product([
            'price' => 500,
            'price_unit' => 'জোড়া',
        ]);

        $this->assertSame('৳ ৫০০/জোড়া', $product->priceWithUnitLabel());
    }
}
