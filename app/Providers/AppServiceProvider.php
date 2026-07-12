<?php

namespace App\Providers;

use App\Contracts\Sms\SmsSender;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Observers\SitemapInvalidationObserver;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SslWirelessSmsSender;
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
        Product::observe(SitemapInvalidationObserver::class);
        Category::observe(SitemapInvalidationObserver::class);
        Page::observe(SitemapInvalidationObserver::class);
    }
}
