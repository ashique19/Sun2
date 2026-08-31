<?php

use App\Services\Ads\AdsLabConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        app(AdsLabConfigService::class)->seedFromDefaultsIfMissing();
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        app(AdsLabConfigService::class)->clearStored();
    }
};
