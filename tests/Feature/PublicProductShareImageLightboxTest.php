<?php

namespace Tests\Feature;

use App\Models\ProductShareList;
use App\Support\StorefrontAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicProductShareImageLightboxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function share_page_product_thumbs_dispatch_enlarge_lightbox(): void
    {
        $imagePath = 'img/thumb/share-necklace_md.jpg';
        $thumb = StorefrontAssets::smallUrl($imagePath);
        $large = StorefrontAssets::largestAvailableUrl($imagePath);

        $this->assertNotNull($thumb);
        $this->assertNotNull($large);

        $share = ProductShareList::query()->create([
            'token' => str_repeat('b', 48),
            'items' => [[
                'key' => 'row-1',
                'product_id' => 1,
                'name' => 'Gold Necklace Set',
                'quantity' => 2,
                'image' => $imagePath,
            ]],
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->get(route('share.products', $share->token));

        $response->assertOk();
        $response->assertSee(__('storefront.share_enlarge_image'), false);
        $response->assertSee('cursor-zoom-in', false);
        $response->assertSee('open-product-image', false);
        $response->assertSee($thumb, false);
        $response->assertSee('share-necklace_lg.jpg', false);
        $response->assertSee('imageUrl:', false);
        $response->assertSee('Gold Necklace Set', false);

        // Storefront layout mounts the shared Alpine lightbox listener.
        $response->assertSee('x-on:open-product-image.window', false);
    }

    #[Test]
    public function share_page_without_image_does_not_render_enlarge_control(): void
    {
        $share = ProductShareList::query()->create([
            'token' => str_repeat('c', 48),
            'items' => [[
                'key' => 'row-2',
                'product_id' => 2,
                'name' => 'No Photo Product',
                'quantity' => 1,
                'image' => null,
            ]],
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->get(route('share.products', $share->token));

        $response->assertOk();
        $response->assertSee(__('storefront.share_no_img'), false);
        $response->assertDontSee(__('storefront.share_enlarge_image'), false);
        $response->assertDontSee('cursor-zoom-in', false);
    }
}
