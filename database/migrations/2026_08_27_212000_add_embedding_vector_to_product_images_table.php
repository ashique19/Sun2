<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GD-only visual embedding (hue histogram + luminance grid) for multi-view fallback.
     */
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->json('embedding_vector')->nullable()->after('dct_hash');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('embedding_vector');
        });
    }
};
