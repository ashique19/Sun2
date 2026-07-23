<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\Seo;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoProductShareTitleTest extends TestCase
{
    #[Test]
    public function it_formats_price_and_name_for_messenger_whatsapp_previews(): void
    {
        $product = new Product([
            'name' => 'Necklace, earring set',
            'price' => 1500,
        ]);

        $this->assertSame('৳ 1,500 (Necklace, earring set)', Seo::productShareTitle($product));
    }

    #[Test]
    public function it_formats_price_without_decimals_when_whole_taka(): void
    {
        $product = new Product([
            'name' => 'Gold plated jhumka',
            'price' => 980.00,
        ]);

        $this->assertSame('৳ 980 (Gold plated jhumka)', Seo::productShareTitle($product));
    }

    #[Test]
    public function it_falls_back_to_price_only_when_name_is_blank(): void
    {
        $product = new Product([
            'name' => '   ',
            'price' => 200,
        ]);

        $this->assertSame('৳ 200', Seo::productShareTitle($product));
    }
}
