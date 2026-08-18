<?php

namespace Tests\Feature;

use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontAssetsTest extends TestCase
{
    #[Test]
    public function prefers_local_file_over_cdn_even_in_production(): void
    {
        $this->app['env'] = 'production';

        $relative = 'img/products/999001/storefront-local-prefer_lg.jpg';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, 'fake-jpeg-bytes');

        try {
            $url = StorefrontAssets::url($relative);

            $this->assertNotNull($url);
            $this->assertStringNotContainsString('sundoritoma.com', $url);
            $this->assertStringContainsString($relative, $url);
        } finally {
            @unlink($absolute);
        }
    }

    #[Test]
    public function falls_back_to_cdn_when_local_file_is_missing(): void
    {
        $this->app['env'] = 'production';

        $relative = 'img/products/999001/missing-on-disk_lg.jpg';
        $this->assertFileDoesNotExist(public_path($relative));

        $url = StorefrontAssets::url($relative);

        $this->assertSame('https://www.sundoritoma.com/public/'.$relative, $url);
    }

    #[Test]
    public function variant_url_prefers_local_file_over_cdn(): void
    {
        $this->app['env'] = 'production';

        $relative = 'img/products/999001/variant-local_md.jpg';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, 'fake-jpeg-bytes');

        try {
            $url = StorefrontAssets::variantUrl('img/products/999001/variant-local_lg.jpg', 'md');

            $this->assertNotNull($url);
            $this->assertStringNotContainsString('sundoritoma.com', $url);
            $this->assertStringContainsString($relative, $url);
        } finally {
            @unlink($absolute);
        }
    }

    #[Test]
    public function unsuffixed_category_thumbs_prefer_local_sibling_variants(): void
    {
        $md = 'img/categories/999002/cat-thumb.jpg';
        $sm = 'img/categories/999002/cat-thumb_sm.jpg';
        File::ensureDirectoryExists(dirname(public_path($md)));
        File::put(public_path($md), 'md-bytes');
        File::put(public_path($sm), 'sm-bytes');

        try {
            $url = StorefrontAssets::smallUrl('/'.$md);

            $this->assertNotNull($url);
            $this->assertStringNotContainsString('sundoritoma.com', $url);
            $this->assertStringContainsString($sm, $url);
        } finally {
            @unlink(public_path($md));
            @unlink(public_path($sm));
            @rmdir(dirname(public_path($md)));
        }
    }

    #[Test]
    public function variant_url_falls_back_to_original_local_file_when_sibling_is_missing(): void
    {
        $relative = 'img/categories/999003/only-master.jpg';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, 'master-bytes');

        try {
            $url = StorefrontAssets::smallUrl('/'.$relative);

            $this->assertNotNull($url);
            $this->assertStringNotContainsString('sundoritoma.com', $url);
            $this->assertStringContainsString($relative, $url);
        } finally {
            @unlink($absolute);
            @rmdir(dirname($absolute));
        }
    }
}
