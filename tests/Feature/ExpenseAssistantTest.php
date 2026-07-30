<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\AdminExpenses;
use App\Models\Expense;
use App\Models\ExpenseAssistantDismissal;
use App\Models\ExpenseRecurringReminder;
use App\Models\User;
use App\Services\Admin\ExpenseAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function migration_seeds_default_monthly_reminders(): void
    {
        $titles = ExpenseRecurringReminder::query()->orderBy('sort_order')->pluck('title')->all();

        $this->assertSame([
            'Salary',
            'Rent',
            'Internet',
            'Credit card',
            'Electricity (prepaid)',
            'Facebook marketing',
        ], $titles);

        $this->assertSame(5, (int) ExpenseRecurringReminder::query()->where('title', 'Salary')->value('due_day'));
        $this->assertSame(20, (int) ExpenseRecurringReminder::query()->where('title', 'Credit card')->value('due_day'));
        $this->assertSame(
            ExpenseRecurringReminder::PROMPT_CHECK,
            ExpenseRecurringReminder::query()->where('title', 'Electricity (prepaid)')->value('prompt_type')
        );
    }

    #[Test]
    public function due_reminders_appear_from_due_day_until_recorded_or_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Dhaka'));

        $admin = $this->adminUser();
        $assistant = app(ExpenseAssistantService::class);

        $salary = ExpenseRecurringReminder::query()->where('title', 'Salary')->firstOrFail();
        $internet = ExpenseRecurringReminder::query()->where('title', 'Internet')->firstOrFail();

        $due = $assistant->dueReminders($admin);
        $this->assertTrue($due->contains('id', $salary->id));
        $this->assertFalse($due->contains('id', $internet->id));

        $assistant->recordPayment($salary, 40000, $admin);
        $due = $assistant->dueReminders($admin);
        $this->assertFalse($due->contains('id', $salary->id));

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Dhaka'));
        $due = $assistant->dueReminders($admin);
        $this->assertTrue($due->contains('id', $internet->id));

        $assistant->skipReminder($internet, $admin);
        $due = $assistant->dueReminders($admin);
        $this->assertFalse($due->contains('id', $internet->id));

        Carbon::setTestNow();
    }

    #[Test]
    public function evening_prompt_only_between_8pm_and_1am_and_can_be_dismissed(): void
    {
        $admin = $this->adminUser();
        $assistant = app(ExpenseAssistantService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-15 15:00:00', 'Asia/Dhaka'));
        $this->assertFalse($assistant->shouldShowEveningPrompt($admin));

        Carbon::setTestNow(Carbon::parse('2026-07-15 20:30:00', 'Asia/Dhaka'));
        $this->assertTrue($assistant->shouldShowEveningPrompt($admin));

        Carbon::setTestNow(Carbon::parse('2026-07-16 00:30:00', 'Asia/Dhaka'));
        $this->assertTrue($assistant->shouldShowEveningPrompt($admin));

        $assistant->dismissEvening($admin);
        $this->assertFalse($assistant->shouldShowEveningPrompt($admin));

        // Same night key (00:30 uses previous evening date)
        $this->assertSame('2026-07-15', $assistant->eveningPeriodKey());

        Carbon::setTestNow(Carbon::parse('2026-07-16 20:00:00', 'Asia/Dhaka'));
        $this->assertTrue($assistant->shouldShowEveningPrompt($admin));

        Carbon::setTestNow();
    }

    #[Test]
    public function dashboard_can_record_skip_and_check_reminders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00', 'Asia/Dhaka'));
        $this->actingAs($this->adminUser());

        $salary = ExpenseRecurringReminder::query()->where('title', 'Salary')->firstOrFail();
        $electricity = ExpenseRecurringReminder::query()->where('title', 'Electricity (prepaid)')->firstOrFail();

        Livewire::test(AdminDashboard::class)
            ->assertSee('Expense assistant')
            ->assertSee('Salary paid?')
            ->assertSee('Electricity (prepaid) checked?')
            ->set('expenseReminderAmounts.'.$salary->id, '40000')
            ->call('recordExpenseReminder', $salary->id)
            ->assertSee('Salary recorded.')
            ->call('markExpenseReminderChecked', $electricity->id)
            ->assertSee('marked as checked');

        $this->assertDatabaseHas('expenses', [
            'title' => 'Salary',
            'amount' => 40000,
            'category' => 'salary',
            'kind' => Expense::KIND_RECURRING,
        ]);

        $this->assertDatabaseHas('expense_assistant_dismissals', [
            'scope' => ExpenseAssistantDismissal::SCOPE_REMINDER_CHECKED,
            'reminder_id' => $electricity->id,
            'period_key' => '2026-07',
        ]);

        Carbon::setTestNow();
    }

    #[Test]
    public function dashboard_evening_flow_records_one_off_expense(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 21:00:00', 'Asia/Dhaka'));
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertSee('Any cost needs to be recorded?')
            ->call('openEveningExpenseForm')
            ->set('eveningExpenseTitle', 'Taxi')
            ->set('eveningExpenseAmount', '350')
            ->set('eveningExpenseCategory', 'other')
            ->call('saveEveningExpense')
            ->assertSee('Expense recorded.')
            ->assertDontSee('Any cost needs to be recorded?');

        $this->assertDatabaseHas('expenses', [
            'title' => 'Taxi',
            'amount' => 350,
            'kind' => Expense::KIND_ONE_TIME,
        ]);

        Carbon::setTestNow();
    }

    #[Test]
    public function expenses_page_can_edit_due_day(): void
    {
        $this->actingAs($this->adminUser());

        $rent = ExpenseRecurringReminder::query()->where('title', 'Rent')->firstOrFail();

        Livewire::test(AdminExpenses::class)
            ->assertSee('Monthly reminders')
            ->assertSee('Rent')
            ->call('editReminder', $rent->id)
            ->set('reminderDueDay', '7')
            ->set('reminderDefaultAmount', '18000')
            ->call('saveReminder')
            ->assertSee('Reminder updated.');

        $this->assertDatabaseHas('expense_recurring_reminders', [
            'id' => $rent->id,
            'due_day' => 7,
            'default_amount' => 18000,
        ]);
    }
}
