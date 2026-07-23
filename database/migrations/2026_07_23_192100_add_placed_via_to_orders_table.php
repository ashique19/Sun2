<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('placed_via', 20)->nullable()->after('created_by');
            $table->index('placed_via');
        });

        // Best-effort backfill from existing creator/reseller fields.
        DB::table('orders')->whereNull('placed_via')->whereNull('created_by')->update([
            'placed_via' => 'storefront',
        ]);

        DB::table('orders')
            ->whereNull('placed_via')
            ->whereNotNull('created_by')
            ->whereColumn('created_by', 'reseller_id')
            ->update(['placed_via' => 'reseller']);

        DB::table('orders')
            ->whereNull('placed_via')
            ->whereNotNull('created_by')
            ->update(['placed_via' => 'admin']);

        DB::table('orders')->whereNull('placed_via')->update([
            'placed_via' => 'storefront',
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['placed_via']);
            $table->dropColumn('placed_via');
        });
    }
};
