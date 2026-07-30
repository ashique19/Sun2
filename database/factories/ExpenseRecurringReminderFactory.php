<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseRecurringReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseRecurringReminder>
 */
class ExpenseRecurringReminderFactory extends Factory
{
    protected $model = ExpenseRecurringReminder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'category' => fake()->randomElement(array_keys(Expense::CATEGORIES)),
            'default_amount' => fake()->optional()->numberBetween(500, 20000),
            'due_day' => fake()->numberBetween(1, 28),
            'prompt_type' => ExpenseRecurringReminder::PROMPT_PAYMENT,
            'notes' => null,
            'is_active' => true,
            'sort_order' => 100,
        ];
    }

    public function check(): static
    {
        return $this->state(fn () => [
            'prompt_type' => ExpenseRecurringReminder::PROMPT_CHECK,
        ]);
    }
}
