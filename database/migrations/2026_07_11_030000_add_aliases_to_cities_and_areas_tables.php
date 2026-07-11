<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('slug');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
