<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ExpenseRecurringReminderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseRecurringReminder extends Model
{
    /** @use HasFactory<ExpenseRecurringReminderFactory> */
    use HasFactory;

    public const PROMPT_PAYMENT = 'payment';

    public const PROMPT_CHECK = 'check';

    /** @var array<string, string> */
    public const PROMPT_TYPES = [
        self::PROMPT_PAYMENT => 'Record payment',
        self::PROMPT_CHECK => 'Monthly check',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'due_day' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function categoryLabel(): string
    {
        return Expense::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function promptTypeLabel(): string
    {
        return self::PROMPT_TYPES[$this->prompt_type] ?? ucfirst((string) $this->prompt_type);
    }

    public function dueDayForMonth(int $year, int $month): int
    {
        $lastDay = (int) Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Dhaka')->endOfMonth()->day;

        return min(max(1, (int) $this->due_day), $lastDay);
    }
}
