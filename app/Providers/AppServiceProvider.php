<?php

namespace App\Providers;

use App\Contracts\Sms\SmsSender;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Observers\SitemapInvalidationObserver;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SslWirelessSmsSender;
use App\Support\Fileinfo;
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
        $this->configureLivewireUploads();
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
