<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseAssistantDismissal extends Model
{
    public const SCOPE_REMINDER_SKIP = 'reminder_skip';

    public const SCOPE_REMINDER_CHECKED = 'reminder_checked';

    public const SCOPE_EVENING = 'evening';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(ExpenseRecurringReminder::class, 'reminder_id');
    }
}
