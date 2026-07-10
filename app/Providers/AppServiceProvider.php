<?php

namespace App\Providers;

use App\Contracts\Sms\SmsSender;
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
        //
    }
}
