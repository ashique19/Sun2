<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_recurring_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category', 32);
            $table->decimal('default_amount', 12, 2)->nullable();
            $table->unsignedTinyInteger('due_day');
            $table->string('prompt_type', 16)->default('payment'); // payment | check
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('expense_recurring_reminders')->insert([
            [
                'title' => 'Salary',
                'category' => 'salary',
                'default_amount' => null,
                'due_day' => 5,
                'prompt_type' => 'payment',
                'notes' => null,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Rent',
                'category' => 'rent',
                'default_amount' => null,
                'due_day' => 5,
                'prompt_type' => 'payment',
                'notes' => null,
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Internet',
                'category' => 'utilities',
                'default_amount' => null,
                'due_day' => 9,
                'prompt_type' => 'payment',
                'notes' => null,
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Credit card',
                'category' => 'other',
                'default_amount' => null,
                'due_day' => 20,
                'prompt_type' => 'payment',
                'notes' => 'Bill usually generated on the 5th; pay by the 20th (or next working day).',
                'is_active' => true,
                'sort_order' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Electricity (prepaid)',
                'category' => 'utilities',
                'default_amount' => null,
                'due_day' => 1,
                'prompt_type' => 'check',
                'notes' => 'Check prepaid balance each month; record a top-up if you recharged.',
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Facebook marketing',
                'category' => 'ads',
                'default_amount' => null,
                'due_day' => 24,
                'prompt_type' => 'payment',
                'notes' => 'FB ads bill typically charged around the 24th.',
                'is_active' => true,
                'sort_order' => 60,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_recurring_reminders');
    }
};
