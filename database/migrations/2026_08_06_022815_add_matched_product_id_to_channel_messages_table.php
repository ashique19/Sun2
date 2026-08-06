<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->foreignId('matched_product_id')
                ->nullable()
                ->after('media_mime')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_product_id');
        });
    }
};
