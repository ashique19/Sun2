<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (! Schema::hasColumn('product_images', 'perceptual_hashes')) {
                $table->json('perceptual_hashes')->nullable()->after('perceptual_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'perceptual_hashes')) {
                $table->dropColumn('perceptual_hashes');
            }
        });
    }
};
