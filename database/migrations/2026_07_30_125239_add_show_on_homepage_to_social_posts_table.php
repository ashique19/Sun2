<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->boolean('show_on_homepage')->default(true)->after('status');
            $table->index(['show_on_homepage', 'status', 'id'], 'idx_social_posts_homepage');
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropIndex('idx_social_posts_homepage');
            $table->dropColumn('show_on_homepage');
        });
    }
};
