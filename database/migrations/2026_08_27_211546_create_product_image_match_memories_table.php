<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff corrections: exact inbound dHash/DCT → product_id.
     * When the same photo hash appears again, auto-match uses this before Hamming search.
     */
    public function up(): void
    {
        Schema::create('product_image_match_memories', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 16);
            $table->string('hash_kind', 8); // dhash | dct
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_channel_message_id')->nullable()->constrained('channel_messages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->unique(['hash', 'hash_kind']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_match_memories');
    }
};
