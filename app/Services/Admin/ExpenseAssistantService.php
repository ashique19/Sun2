<?php

namespace App\Services\Admin;

use App\Models\Expense;
use App\Models\ExpenseAssistantDismissal;
use App\Models\ExpenseRecurringReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ExpenseAssistantService
{
    public function now(): Carbon
    {
        return now('Asia/Dhaka');
    }

    public function isEveningWindow(?Carbon $at = null): bool
    {
        $hour = ($at ?? $this->now())->hour;

        return $hour >= 20 || $hour < 1;
    }

    /**
     * Night key for 8pm–1am window (hour 0 belongs to previous calendar evening).
     */
    public function eveningPeriodKey(?Carbon $at = null): string
    {
        $at = ($at ?? $this->now())->copy();

        if ($at->hour < 1) {
            $at->subDay();
        }

        return $at->toDateString();
    }

    public function monthPeriodKey(?Carbon $at = null): string
    {
        return ($at ?? $this->now())->format('Y-m');
    }

    /**
     * Active reminders due today or earlier this month that still need action.
     *
     * @return Collection<int, ExpenseRecurringReminder>
     */
    public function dueReminders(?User $user = null, ?Carbon $at = null): Collection
    {
        $at = $at ?? $this->now();
        $year = (int) $at->year;
        $month = (int) $at->month;
        $day = (int) $at->day;
        $monthKey = $this->monthPeriodKey($at);

        $skippedIds = [];
        $checkedIds = [];

        if ($user) {
            $dismissals = ExpenseAssistantDismissal::query()
                ->where('user_id', $user->id)
                ->where('period_key', $monthKey)
                ->whereIn('scope', [
                    ExpenseAssistantDismissal::SCOPE_REMINDER_SKIP,
                    ExpenseAssistantDismissal::SCOPE_REMINDER_CHECKED,
                ])
                ->get(['reminder_id', 'scope']);

            $skippedIds = $dismissals
                ->where('scope', ExpenseAssistantDismissal::SCOPE_REMINDER_SKIP)
                ->pluck('reminder_id')
                ->filter()
                ->all();
            $checkedIds = $dismissals
                ->where('scope', ExpenseAssistantDismissal::SCOPE_REMINDER_CHECKED)
                ->pluck('reminder_id')
                ->filter()
                ->all();
        }

        return ExpenseRecurringReminder::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (ExpenseRecurringReminder $reminder) use ($year, $month, $day, $skippedIds, $checkedIds) {
                $dueDay = $reminder->dueDayForMonth($year, $month);

                if ($day < $dueDay) {
                    return false;
                }

                if (in_array($reminder->id, $skippedIds, true)) {
                    return false;
                }

                if (
                    $reminder->prompt_type === ExpenseRecurringReminder::PROMPT_CHECK
                    && in_array($reminder->id, $checkedIds, true)
                ) {
                    return false;
                }

                return ! $this->isRecordedForMonth($reminder, $year, $month);
            })
            ->values();
    }

    public function isRecordedForMonth(ExpenseRecurringReminder $reminder, int $year, int $month): bool
    {
        return Expense::query()
            ->where('title', $reminder->title)
            ->where('category', $reminder->category)
            ->forMonth($year, $month)
            ->exists();
    }

    public function shouldShowEveningPrompt(?User $user = null, ?Carbon $at = null): bool
    {
        if (! $this->isEveningWindow($at)) {
            return false;
        }

        if (! $user) {
            return true;
        }

        return ! ExpenseAssistantDismissal::query()
            ->where('user_id', $user->id)
            ->where('scope', ExpenseAssistantDismissal::SCOPE_EVENING)
            ->where('period_key', $this->eveningPeriodKey($at))
            ->exists();
    }

    public function recordPayment(
        ExpenseRecurringReminder $reminder,
        float $amount,
        ?User $user = null,
        ?Carbon $at = null,
    ): Expense {
        $at = $at ?? $this->now();

        return Expense::query()->create([
            'title' => $reminder->title,
            'amount' => round($amount, 2),
            'category' => $reminder->category,
            'kind' => Expense::KIND_RECURRING,
            'spent_on' => $at->toDateString(),
            'notes' => $reminder->notes,
            'created_by' => $user?->id,
        ]);
    }

    public function markChecked(ExpenseRecurringReminder $reminder, User $user, ?Carbon $at = null): void
    {
        $this->rememberDismissal(
            user: $user,
            scope: ExpenseAssistantDismissal::SCOPE_REMINDER_CHECKED,
            periodKey: $this->monthPeriodKey($at),
            reminderId: $reminder->id,
        );
    }

    public function skipReminder(ExpenseRecurringReminder $reminder, User $user, ?Carbon $at = null): void
    {
        $this->rememberDismissal(
            user: $user,
            scope: ExpenseAssistantDismissal::SCOPE_REMINDER_SKIP,
            periodKey: $this->monthPeriodKey($at),
            reminderId: $reminder->id,
        );
    }

    public function dismissEvening(User $user, ?Carbon $at = null): void
    {
        $this->rememberDismissal(
            user: $user,
            scope: ExpenseAssistantDismissal::SCOPE_EVENING,
            periodKey: $this->eveningPeriodKey($at),
            reminderId: null,
        );
    }

    private function rememberDismissal(User $user, string $scope, string $periodKey, ?int $reminderId): void
    {
        $dedupeKey = $scope.':'.($reminderId ?? 0).':'.$periodKey;

        ExpenseAssistantDismissal::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'dedupe_key' => $dedupeKey,
            ],
            [
                'scope' => $scope,
                'reminder_id' => $reminderId,
                'period_key' => $periodKey,
            ],
        );
    }

    public function recordOneOff(
        string $title,
        float $amount,
        string $category,
        ?User $user = null,
        ?Carbon $at = null,
        ?string $notes = null,
    ): Expense {
        $at = $at ?? $this->now();

        return Expense::query()->create([
            'title' => trim($title),
            'amount' => round($amount, 2),
            'category' => $category,
            'kind' => Expense::KIND_ONE_TIME,
            'spent_on' => $at->toDateString(),
            'notes' => filled($notes) ? trim($notes) : null,
            'created_by' => $user?->id,
        ]);
    }
}
