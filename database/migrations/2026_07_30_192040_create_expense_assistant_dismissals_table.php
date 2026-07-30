<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_assistant_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32); // reminder_skip | reminder_checked | evening
            $table->foreignId('reminder_id')->nullable()->constrained('expense_recurring_reminders')->cascadeOnDelete();
            $table->string('period_key', 16); // Y-m for month scopes, Y-m-d for evening night
            $table->string('dedupe_key', 64);
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_assistant_dismissals');
    }
};
