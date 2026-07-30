<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public const KIND_ONE_TIME = 'one_time';

    public const KIND_RECURRING = 'recurring';

    /** @var array<string, string> */
    public const CATEGORIES = [
        'salary' => 'Salary',
        'rent' => 'Rent',
        'ads' => 'Ads / marketing',
        'utilities' => 'Utilities',
        'tools' => 'Tools / software',
        'other' => 'Other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_on' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_RECURRING ? 'Recurring' : 'One-time';
    }

    /**
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return $query->whereBetween('spent_on', [$start, $end]);
    }
}
