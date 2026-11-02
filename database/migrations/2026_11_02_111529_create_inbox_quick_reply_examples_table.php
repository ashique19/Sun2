<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('channel_messages', 'quick_reply_id')) {
            Schema::table('channel_messages', function (Blueprint $table) {
                $table->string('quick_reply_id', 40)->nullable()->after('matched_product_id');
                $table->index('quick_reply_id');
            });
        }

        if (! Schema::hasTable('inbox_quick_reply_examples')) {
            Schema::create('inbox_quick_reply_examples', function (Blueprint $table) {
                $table->id();
                $table->string('quick_reply_id', 40);
                $table->text('example_text');
                $table->string('example_normalized', 191);
                $table->foreignId('channel_message_id')->nullable()->constrained('channel_messages')->nullOnDelete();
                $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique('channel_message_id');
                // Named under MySQL's 64-char identifier limit (auto name is 67).
                $table->index(['quick_reply_id', 'example_normalized'], 'iqre_qr_normalized_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_quick_reply_examples');

        if (Schema::hasColumn('channel_messages', 'quick_reply_id')) {
            Schema::table('channel_messages', function (Blueprint $table) {
                $table->dropIndex(['quick_reply_id']);
                $table->dropColumn('quick_reply_id');
            });
        }
    }
};
