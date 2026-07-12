<?php

namespace App\Providers;

use App\Contracts\Sms\SmsSender;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Observers\SitemapInvalidationObserver;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SslWirelessSmsSender;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'ssl_wireless' => new SslWirelessSmsSender,
                default => new LogSmsSender,
            };
        });
    }

    public function boot(): void
    {
        $this->configurePublicUrl();
        $this->ensureLivewireUploadDirectory();

        Product::observe(SitemapInvalidationObserver::class);
        Category::observe(SitemapInvalidationObserver::class);
        Page::observe(SitemapInvalidationObserver::class);
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

    private function ensureLivewireUploadDirectory(): void
    {
        $directory = storage_path('app/livewire-tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
