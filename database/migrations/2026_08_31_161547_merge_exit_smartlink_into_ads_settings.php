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

        app(AdsLabConfigService::class)->mergeMissingDefaults();
    }

    public function down(): void
    {
        // Keep exit_smartlink in settings — removing would drop production config.
    }
};
