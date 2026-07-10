<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('steadfast_id');
            $table->string('slug')->nullable()->unique()->after('id');
        });

        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('steadfast_id');
            $table->string('slug')->nullable()->unique()->after('id');
            $table->string('unit_type', 32)->nullable()->after('police_station');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn(['slug', 'unit_type']);
            $table->unsignedInteger('steadfast_id')->nullable()->unique();
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('slug');
            $table->unsignedInteger('steadfast_id')->nullable()->unique();
        });
    }
};
