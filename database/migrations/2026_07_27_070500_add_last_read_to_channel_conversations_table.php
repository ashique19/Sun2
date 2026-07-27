<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('last_outbound_at');
            $table->foreignId('last_read_by')->nullable()->after('last_read_at')->constrained('users')->nullOnDelete();
            $table->index(['last_read_at', 'last_inbound_at'], 'channel_conversations_read_inbound_index');
        });
    }

    public function down(): void
    {
        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_read_by');
            $table->dropIndex('channel_conversations_read_inbound_index');
            $table->dropColumn('last_read_at');
        });
    }
};
