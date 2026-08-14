<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('facebook:refresh-page-token')
    ->daily()
    ->withoutOverlapping();

Schedule::command('messenger:sync-conversations')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('facebook.messenger.auto_sync_enabled', false));

Schedule::command('channels:purge-inbox')
    ->daily()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('channels.inbox.purge_schedule_enabled', true));
