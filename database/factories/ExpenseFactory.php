<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'amount' => fake()->numberBetween(500, 25000),
            'category' => fake()->randomElement(array_keys(Expense::CATEGORIES)),
            'kind' => Expense::KIND_ONE_TIME,
            'spent_on' => now('Asia/Dhaka')->toDateString(),
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn () => [
            'kind' => Expense::KIND_RECURRING,
        ]);
    }
}
