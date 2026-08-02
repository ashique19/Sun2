<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('return_hub_arrived_at')->nullable()->after('has_return');
            $table->index('return_hub_arrived_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['return_hub_arrived_at']);
            $table->dropColumn('return_hub_arrived_at');
        });
    }
};
