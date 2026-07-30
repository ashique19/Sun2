<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_products', function (Blueprint $table) {
            $table->string('selected_image_path')->nullable()->after('priced_snapshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_products', function (Blueprint $table) {
            $table->dropColumn('selected_image_path');
        });
    }
};
