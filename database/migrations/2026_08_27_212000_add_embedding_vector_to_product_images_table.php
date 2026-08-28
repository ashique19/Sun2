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
        if (Schema::hasColumn('product_images', 'embedding_vector')) {
            return;
        }

        Schema::table('product_images', function (Blueprint $table) {
            $afterColumn = match (true) {
                Schema::hasColumn('product_images', 'dct_hash') => 'dct_hash',
                Schema::hasColumn('product_images', 'perceptual_hashes') => 'perceptual_hashes',
                Schema::hasColumn('product_images', 'perceptual_hash') => 'perceptual_hash',
                default => 'path',
            };

            $table->json('embedding_vector')->nullable()->after($afterColumn);
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'embedding_vector')) {
                $table->dropColumn('embedding_vector');
            }
        });
    }
};
