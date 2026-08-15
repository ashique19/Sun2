<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('ai_image_prompts', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_image_prompts', 'ai_prompt_group_id')) {
                $table->foreignId('ai_prompt_group_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('ai_prompt_groups')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('ai_image_prompts', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('prompt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_image_prompts', function (Blueprint $table) {
            if (Schema::hasColumn('ai_image_prompts', 'ai_prompt_group_id')) {
                $table->dropConstrainedForeignId('ai_prompt_group_id');
            }

            if (Schema::hasColumn('ai_image_prompts', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });

        Schema::dropIfExists('ai_prompt_groups');
    }
};
