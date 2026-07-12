<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_hash_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('pending')->index();
            $table->string('trigger', 32);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('force')->default(false);
            $table->string('phase')->nullable();
            $table->string('message')->nullable();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('hashed_ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('image_cursor')->default(0);
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_hash_runs');
    }
};
