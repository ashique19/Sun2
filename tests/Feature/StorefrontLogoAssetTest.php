<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontLogoAssetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function storefront_uses_png_brand_logo_not_placeholder_svg(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('src="/img/settings/logo.png"', false);
        $response->assertDontSee('src="/img/settings/logo.svg"', false);
        $response->assertDontSee('og:image" content="'.url('/img/settings/logo.svg').'"', false);
    }
}
