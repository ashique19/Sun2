<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_data', function (Blueprint $table) {
            $table->timestamp('inbox_dismissed_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('courier_data', function (Blueprint $table) {
            $table->dropColumn('inbox_dismissed_at');
        });
    }
};
