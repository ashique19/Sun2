<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('priced_image_path')->nullable()->after('compare_at_price');
            $table->json('priced_image_layout')->nullable()->after('priced_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['priced_image_path', 'priced_image_layout']);
        });
    }
};
