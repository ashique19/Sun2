<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('channel_messages', 'media_path')) {
                $table->string('media_path', 500)->nullable()->after('media_mime');
            }
            if (! Schema::hasColumn('channel_messages', 'media_dhash')) {
                $table->string('media_dhash', 16)->nullable()->after('media_path');
            }
            if (! Schema::hasColumn('channel_messages', 'media_dct_hash')) {
                $table->string('media_dct_hash', 16)->nullable()->after('media_dhash');
            }
        });

        if (! Schema::hasColumn('product_images', 'dct_hash')) {
            Schema::table('product_images', function (Blueprint $table) {
                $afterColumn = match (true) {
                    Schema::hasColumn('product_images', 'perceptual_hashes') => 'perceptual_hashes',
                    Schema::hasColumn('product_images', 'perceptual_hash') => 'perceptual_hash',
                    default => 'path',
                };

                $table->string('dct_hash', 16)->nullable()->after($afterColumn);
                $table->index('dct_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            foreach (['media_dct_hash', 'media_dhash', 'media_path'] as $column) {
                if (Schema::hasColumn('channel_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('product_images', function (Blueprint $table) {
            if (Schema::hasColumn('product_images', 'dct_hash')) {
                $table->dropIndex(['dct_hash']);
                $table->dropColumn('dct_hash');
            }
        });
    }
};
