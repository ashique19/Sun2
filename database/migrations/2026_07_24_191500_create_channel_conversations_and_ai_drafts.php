<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20); // messenger | whatsapp
            $table->string('external_user_id', 128);
            $table->string('external_account_id', 128)->nullable(); // page id / WA phone number id
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('draft_order_id')->nullable()->index();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_user_id'], 'channel_conversations_channel_user_unique');
            $table->index(['channel', 'last_inbound_at']);
        });

        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_conversation_id')->constrained('channel_conversations')->cascadeOnDelete();
            $table->string('external_message_id', 191)->nullable();
            $table->string('direction', 16); // inbound | outbound
            $table->text('body')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('media_mime', 128)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_conversation_id', 'external_message_id'], 'channel_messages_conversation_external_unique');
            $table->index(['channel_conversation_id', 'sent_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('channel_conversation_id')
                ->nullable()
                ->after('placed_via')
                ->constrained('channel_conversations')
                ->nullOnDelete();
            $table->json('ai_parse_meta')->nullable()->after('channel_conversation_id');
        });

        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->foreign('draft_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->dropForeign(['draft_order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_conversation_id');
            $table->dropColumn('ai_parse_meta');
        });

        Schema::dropIfExists('channel_messages');
        Schema::dropIfExists('channel_conversations');
    }
};
