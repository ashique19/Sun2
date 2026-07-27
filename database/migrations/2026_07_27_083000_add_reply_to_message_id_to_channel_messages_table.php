<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->after('external_message_id')
                ->constrained('channel_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_message_id');
        });
    }
};
