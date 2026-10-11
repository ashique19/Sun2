<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember that an inbound photo was already matched (including a miss)
     * so reopening the conversation does not hash it again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('channel_messages', 'image_match_checked_at')) {
            return;
        }

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->timestamp('image_match_checked_at')->nullable()->after('matched_product_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('channel_messages', 'image_match_checked_at')) {
            return;
        }

        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropColumn('image_match_checked_at');
        });
    }
};
