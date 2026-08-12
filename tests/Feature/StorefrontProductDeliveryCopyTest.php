<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontProductDeliveryCopyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function product_detail_shows_nationwide_home_delivery_copy(): void
    {
        $product = Product::query()->create([
            'name' => 'Test Necklace',
            'slug' => 'test-necklace',
            'sku' => 'TN-1',
            'price' => 500,
            'is_published' => true,
            'display_order' => 1,
        ]);

        $this->get(route('product.show', $product))
            ->assertOk()
            ->assertSee('সারা দেশে হোম ডেলিভারি', false)
            ->assertDontSee('ঢাকায় ফ্রি ডেলিভারি', false);
    }
}
