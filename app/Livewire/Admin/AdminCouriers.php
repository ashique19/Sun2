<?php

namespace App\Livewire\Admin;

use App\Models\Courier;
use App\Services\Admin\CourierBalanceService;
use App\Services\Couriers\CourierApiRegistry;
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

    public bool $showNeutralizeModal = false;

    public ?int $neutralizeCourierId = null;

    public string $neutralizeCourierName = '';

    public string $neutralizeCurrentReceivable = '0';

    public string $neutralizeBook = '0';

    public string $neutralizeApi = '';

    public string $neutralizeTarget = '';

    public string $neutralizeNote = '';

    /** @var array<int, float|null> */
    public array $apiBalances = [];

    public bool $apiBalancesLoaded = false;

    public bool $apiBalancesLoading = false;

    public ?string $apiBalanceError = null;

    public bool $showDiffModal = false;

    public ?int $diffCourierId = null;

    public string $diffCourierName = '';

    public string $diffApiBalance = '0';

    public string $diffExpectedApi = '0';

    public string $diffAmount = '0';

    /**
     * @var list<array{
     *     order_id: int,
     *     order_number: string,
     *     customer: string,
     *     status: string,
     *     reason: string,
     *     reason_label: string,
     *     book_expected: float,
     *     courier_collected: float|null,
     *     delta: float,
     *     attention_id: int|null,
     *     tracking_message: string|null
     * }>
     */
    public array $diffOrders = [];

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

    public function openNeutralize(int $courierId, CourierBalanceService $balances): void
    {
        $this->error = null;
        $this->message = null;
        $this->resetValidation();

        $courier = Courier::query()->findOrFail($courierId);
        $summary = $balances->summarize($courier);
        $receivable = (int) round((float) $summary['receivable']);
        $book = (int) round((float) $summary['book']);
        $api = $this->apiBalances[$courier->id] ?? null;

        $this->neutralizeCourierId = $courier->id;
        $this->neutralizeCourierName = $courier->name;
        $this->neutralizeCurrentReceivable = (string) $receivable;
        $this->neutralizeBook = (string) $book;
        $this->neutralizeApi = $api !== null ? (string) (int) round((float) $api) : '';
        // Prefer live API; otherwise Zero when current is negative, else leave blank.
        $this->neutralizeTarget = $this->neutralizeApi !== ''
            ? $this->neutralizeApi
            : ($receivable < 0 ? '0' : '');
        $this->neutralizeNote = '';
        $this->showNeutralizeModal = true;
    }

    public function closeNeutralize(): void
    {
        $this->showNeutralizeModal = false;
        $this->neutralizeCourierId = null;
        $this->neutralizeTarget = '';
        $this->neutralizeNote = '';
        $this->neutralizeApi = '';
        $this->resetValidation();
    }

    public function setNeutralizeTargetToApi(): void
    {
        if ($this->neutralizeApi !== '') {
            $this->neutralizeTarget = $this->neutralizeApi;
        }
    }

    public function setNeutralizeTargetToBook(): void
    {
        $this->neutralizeTarget = $this->neutralizeBook;
    }

    public function setNeutralizeTargetToZero(): void
    {
        $this->neutralizeTarget = '0';
    }

    public function confirmNeutralize(CourierBalanceService $balances): void
    {
        $this->error = null;
        $this->message = null;

        $current = (int) round((float) $this->neutralizeCurrentReceivable);

        $this->validate([
            'neutralizeCourierId' => ['required', 'integer', 'exists:couriers,id'],
            'neutralizeTarget' => ['required', 'integer', 'min:0', 'not_in:'.$current],
            'neutralizeNote' => ['nullable', 'string', 'max:255'],
        ], [
            'neutralizeTarget.not_in' => 'Target matches current receivable (৳'.number_format($current, 0).'). Nothing to neutralize.',
        ]);

        $courier = Courier::query()->findOrFail($this->neutralizeCourierId);

        $balances->neutralizeReceivable(
            $courier,
            (int) $this->neutralizeTarget,
            $this->neutralizeNote !== '' ? $this->neutralizeNote : null,
        );

        $this->closeNeutralize();
        $this->message = 'Prior remittances recorded for '.$courier->name.'. Receivable updated; book unchanged.';
    }

    public function openDiffOrders(int $courierId, CourierBalanceService $balances): void
    {
        $courier = Courier::query()->findOrFail($courierId);
        $summary = $balances->summarize($courier);
        $apiBalance = $this->apiBalances[$courier->id] ?? null;

        if ($apiBalance === null) {
            return;
        }

        $expectedApi = (float) $summary['expected_api'];
        $diff = round((float) $apiBalance - $expectedApi, 2);

        if (abs($diff) < 0.5) {
            return;
        }

        $this->diffCourierId = $courier->id;
        $this->diffCourierName = $courier->name;
        $this->diffApiBalance = (string) round((float) $apiBalance, 2);
        $this->diffExpectedApi = (string) $expectedApi;
        $this->diffAmount = (string) $diff;
        $this->diffOrders = $balances->mismatchOrders($courier);
        $this->showDiffModal = true;
    }

    public function closeDiffOrders(): void
    {
        $this->showDiffModal = false;
        $this->diffCourierId = null;
        $this->diffCourierName = '';
        $this->diffApiBalance = '0';
        $this->diffExpectedApi = '0';
        $this->diffAmount = '0';
        $this->diffOrders = [];
    }

    /**
     * Fetch live courier wallet balances on demand (Refresh API button).
     * Never called during initial render — Steadfast outages must not block this page.
     */
    public function loadApiBalances(CourierBalanceService $balances, CourierApiRegistry $registry): void
    {
        if ($this->apiBalancesLoading) {
            return;
        }

        $this->apiBalancesLoading = true;
        $this->apiBalanceError = null;

        $couriers = Courier::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'slug']);

        $configuredCourier = $couriers->first(
            fn (Courier $courier) => filled($courier->slug) && $registry->isConfigured(strtolower((string) $courier->slug))
        );

        $fetched = $balances->fetchApiBalancesFor($couriers);
        $this->apiBalances = $fetched;

        if ($configuredCourier !== null && ($fetched[$configuredCourier->id] ?? null) === null) {
            $this->apiBalanceError = 'API balance unavailable right now.';
        }

        $this->apiBalancesLoaded = true;
        $this->apiBalancesLoading = false;
    }

    public function render(CourierBalanceService $balances)
    {
        $couriers = Courier::query()
            ->withCount('orders')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $summaries = $balances->summarizeMany($couriers);

        return view('livewire.admin.admin-couriers', [
            'couriers' => $couriers,
            'apiSlugs' => config('couriers.api_slugs', []),
            'balanceSummaries' => $summaries,
            'totalPending' => array_sum(array_column($summaries, 'pending')),
            'totalReceivable' => array_sum(array_column($summaries, 'receivable')),
        ]);
    }
}
