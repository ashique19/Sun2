<?php

namespace Tests\Feature;

use App\Livewire\StorefrontProduct;
use App\Models\Product;
use App\Support\ProductDescriptionHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductDescriptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function storefront_prefers_bangla_and_renders_sanitized_html(): void
    {
        $product = Product::query()->create([
            'name' => 'Ruby Ring',
            'slug' => 'ruby-ring',
            'price' => 1500,
            'is_published' => true,
            'description' => '<p>English only</p><script>bad()</script>',
            'description_bn' => '<p>বাংলা বিবরণ</p><script>bad()</script>',
        ]);

        $html = ProductDescriptionHtml::forStorefront($product);
        $this->assertSame('<p>বাংলা বিবরণ</p>', $html);
        $this->assertTrue(ProductDescriptionHtml::hasBothLanguages($product));

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertSeeHtml('data-description-lang-switch')
            ->assertSeeHtml('data-description-lang-en')
            ->assertSeeHtml('<p>বাংলা বিবরণ</p>')
            ->assertSeeHtml('<p>English only</p>')
            ->assertDontSee('alert');
    }

    #[Test]
    public function storefront_hides_language_switch_when_only_one_description_exists(): void
    {
        $product = Product::query()->create([
            'name' => 'Copper Bangle',
            'slug' => 'copper-bangle',
            'price' => 600,
            'is_published' => true,
            'description' => '<p>English only body</p>',
            'description_bn' => null,
        ]);

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertDontSeeHtml('data-description-lang-switch')
            ->assertSeeHtml('<p>English only body</p>');
    }

    #[Test]
    public function storefront_falls_back_to_english_when_bangla_empty(): void
    {
        $product = Product::query()->create([
            'name' => 'Silver Chain',
            'slug' => 'silver-chain',
            'price' => 800,
            'is_published' => true,
            'description' => '<p>English body</p><ul><li>Handcrafted</li></ul>',
            'description_bn' => null,
        ]);

        Livewire::test(StorefrontProduct::class, ['product' => $product])
            ->assertSeeHtml('<p>English body</p>')
            ->assertSeeHtml('<li>Handcrafted</li>');
    }

    #[Test]
    public function for_storefront_helper_matches_locale_preference(): void
    {
        $withBn = Product::query()->create([
            'name' => 'A',
            'slug' => 'a-desc',
            'price' => 1,
            'is_published' => true,
            'description' => '<p>EN</p>',
            'description_bn' => '<p>BN</p>',
        ]);

        $enOnly = Product::query()->create([
            'name' => 'B',
            'slug' => 'b-desc',
            'price' => 1,
            'is_published' => true,
            'description' => '<p>EN only</p>',
            'description_bn' => '',
        ]);

        $this->assertSame('<p>BN</p>', ProductDescriptionHtml::forStorefront($withBn));
        $this->assertSame('<p>EN only</p>', ProductDescriptionHtml::forStorefront($enOnly));
    }
}
