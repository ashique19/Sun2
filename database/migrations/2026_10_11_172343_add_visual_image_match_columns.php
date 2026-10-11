<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visual screenshot matcher: catalog region signatures, staff-learned signatures,
     * and the per-photo candidate list (suggestions + "Not this" rejections).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('product_images', 'match_signature')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->text('match_signature')->nullable()->after('embedding_vector');
            });
        }

        if (! Schema::hasColumn('product_image_match_memories', 'signature')) {
            Schema::table('product_image_match_memories', function (Blueprint $table) {
                $table->text('signature')->nullable()->after('hash_kind');
            });
        }

        if (! Schema::hasColumn('channel_messages', 'image_match_meta')) {
            Schema::table('channel_messages', function (Blueprint $table) {
                $table->json('image_match_meta')->nullable()->after('image_match_checked_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('channel_messages', 'image_match_meta')) {
            Schema::table('channel_messages', function (Blueprint $table) {
                $table->dropColumn('image_match_meta');
            });
        }

        if (Schema::hasColumn('product_image_match_memories', 'signature')) {
            Schema::table('product_image_match_memories', function (Blueprint $table) {
                $table->dropColumn('signature');
            });
        }

        if (Schema::hasColumn('product_images', 'match_signature')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropColumn('match_signature');
            });
        }
    }
};
