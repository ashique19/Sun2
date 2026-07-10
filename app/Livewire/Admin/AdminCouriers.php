<?php

namespace App\Livewire\Admin;

use App\Models\Courier;
use App\Services\Admin\CourierBalanceService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Couriers')]
#[Layout('components.layouts.admin')]
class AdminCouriers extends Component
{
    public ?string $error = null;

    public ?string $message = null;

    public bool $showWithdrawModal = false;

    public ?int $withdrawCourierId = null;

    public string $withdrawCourierName = '';

    public string $withdrawBookBalance = '0';

    public string $withdrawAmount = '';

    public string $withdrawNote = '';

    public function delete(int $courierId): void
    {
        $this->error = null;
        $this->message = null;

        $courier = Courier::query()->withCount('orders')->findOrFail($courierId);

        if ($courier->orders_count > 0) {
            $this->error = 'Cannot delete “'.$courier->name.'” while orders still reference it. Deactivate it instead.';

            return;
        }

        if ($courier->is_default) {
            $this->error = 'Cannot delete the default courier. Set another courier as default first.';

            return;
        }

        $courier->delete();
        $this->message = 'Courier deleted.';
    }

    public function openWithdraw(int $courierId): void
    {
        $this->error = null;
        $this->message = null;
        $this->resetValidation();

        $courier = Courier::query()->findOrFail($courierId);

        $this->withdrawCourierId = $courier->id;
        $this->withdrawCourierName = $courier->name;
        $this->withdrawBookBalance = (string) (int) round((float) $courier->balance);
        $this->withdrawAmount = '';
        $this->withdrawNote = '';
        $this->showWithdrawModal = true;
    }

    public function closeWithdraw(): void
    {
        $this->showWithdrawModal = false;
        $this->withdrawCourierId = null;
        $this->withdrawAmount = '';
        $this->withdrawNote = '';
        $this->resetValidation();
    }

    public function confirmWithdraw(CourierBalanceService $balances): void
    {
        $this->error = null;
        $this->message = null;

        $maxBalance = max(0, (int) round((float) $this->withdrawBookBalance));

        $this->validate([
            'withdrawCourierId' => ['required', 'integer', 'exists:couriers,id'],
            'withdrawAmount' => ['required', 'integer', 'min:1', 'max:'.$maxBalance],
            'withdrawNote' => ['nullable', 'string', 'max:255'],
        ], [
            'withdrawAmount.max' => 'Withdraw amount cannot be greater than the book balance (৳'.number_format($maxBalance, 0).').',
        ]);

        $courier = Courier::query()->findOrFail($this->withdrawCourierId);

        // Re-read balance so we never exceed the latest book amount.
        $this->withdrawBookBalance = (string) (int) round((float) $courier->balance);

        $balances->withdraw(
            $courier,
            (int) $this->withdrawAmount,
            $this->withdrawNote !== '' ? $this->withdrawNote : null,
        );

        $this->closeWithdraw();
        $this->message = 'Withdrawal recorded for '.$courier->name.'.';
    }

    public function render(CourierBalanceService $balances)
    {
        $couriers = Courier::query()
            ->withCount('orders')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.admin-couriers', [
            'couriers' => $couriers,
            'apiSlugs' => config('couriers.api_slugs', []),
            'apiBalances' => $balances->fetchApiBalancesFor($couriers),
        ]);
    }
}
