<?php

namespace App\Providers;

use App\Contracts\Sms\SmsSender;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageMatchMemory;
use App\Observers\SitemapInvalidationObserver;
use App\Services\Admin\ProductImageCatalogIndex;
use App\Services\Admin\ProductImageMatchIndex;
use App\Services\Channels\ProductImageMatchMemoryService;
use App\Services\Facebook\FacebookPageTokenService;
use App\Services\Sms\LoggingSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\MimSmsSender;
use App\Services\Sms\SslWirelessSmsSender;
use App\Support\Fileinfo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function () {
            $sender = match (config('sms.driver')) {
                'ssl_wireless' => new SslWirelessSmsSender,
                'mimsms' => new MimSmsSender,
                default => new LogSmsSender,
            };

            return new LoggingSmsSender($sender);
        });
    }

    public function boot(): void
    {
        $this->configurePublicUrl();
        $this->configureLivewireUploads();
        $this->ensureLivewireUploadDirectory();
        $this->applyFacebookTokenOverride();

        Product::observe(SitemapInvalidationObserver::class);
        Category::observe(SitemapInvalidationObserver::class);
        Page::observe(SitemapInvalidationObserver::class);
        ProductImage::observe(SitemapInvalidationObserver::class);

        ProductImage::saved(function (ProductImage $image): void {
            ProductImageCatalogIndex::bump();
            if ($image->wasChanged('match_signature') || ($image->wasRecentlyCreated && $image->match_signature !== null)) {
                ProductImageMatchIndex::forget();
            }
        });
        ProductImage::deleted(function (ProductImage $image): void {
            ProductImageCatalogIndex::bump();
            if ($image->match_signature !== null) {
                ProductImageMatchIndex::forget();
            }
        });
        Product::saved(function (Product $product): void {
            if ($product->wasChanged('is_published')) {
                ProductImageCatalogIndex::bump();
            }
        });
        ProductImageMatchMemory::saved(function (ProductImageMatchMemory $memory): void {
            ProductImageMatchMemoryService::flush();
            if ($memory->wasRecentlyCreated || $memory->wasChanged(['hash', 'hash_kind', 'product_id', 'signature'])) {
                ProductImageCatalogIndex::bumpMemory();
            }
            if ($memory->signature !== null && ($memory->wasRecentlyCreated || $memory->wasChanged(['signature', 'product_id']))) {
                ProductImageMatchIndex::forget();
            }
        });
        ProductImageMatchMemory::deleted(function (ProductImageMatchMemory $memory): void {
            ProductImageMatchMemoryService::flush();
            ProductImageCatalogIndex::bumpMemory();
            if ($memory->signature !== null) {
                ProductImageMatchIndex::forget();
            }
        });
    }

    private function applyFacebookTokenOverride(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            app(FacebookPageTokenService::class)->applyRuntimeConfig();
        } catch (Throwable) {
            // Ignore during early boot / migrate before settings exists.
        }
    }

    /**
     * Signed Livewire upload URLs fail with a generic "failed to upload" when
     * APP_URL scheme/host does not match the browser (common behind HTTPS proxies).
     */
    private function configurePublicUrl(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Use real image/mime validation when fileinfo is on; otherwise keep uploads working
     * with presence/size checks only on the temporary upload endpoint.
     */
    private function configureLivewireUploads(): void
    {
        config([
            'livewire.temporary_file_upload.rules' => Fileinfo::temporaryUploadRules(),
        ]);
    }

    private function ensureLivewireUploadDirectory(): void
    {
        $directory = storage_path('app/livewire-tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
