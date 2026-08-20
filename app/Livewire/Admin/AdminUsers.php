<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CustomerAnalyticsService;
use App\Services\Admin\CustomerExportService;
use App\Services\Sms\PromotionalSmsService;
use App\Support\AdminAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.admin')]
class AdminUsers extends Component
{
    use WithPagination;

    public string $segment = 'customers';

    #[Url]
    public string $search = '';

    #[Url(as: 'city')]
    public string $cityFilter = '';

    #[Url(as: 'city_none')]
    public bool $cityNoneOnly = false;

    #[Url]
    public string $ordersMin = '';

    #[Url]
    public string $ordersMax = '';

    #[Url(as: 'category')]
    public string $categoryId = '';

    #[Url(as: 'analytics')]
    public string $analyticsPill = '';

    public bool $analyticsModalOpen = false;

    public bool $analyticsMinimized = false;

    public bool $analyticsLoading = false;

    public string $analyticsReportSearch = '';

    public bool $analyticsShowAllRows = false;

    /** @var array<string, list<array{key: string, label: string, count: int}>> */
    public array $analyticsCache = [];

    /** @var list<array{key: string, label: string, count: int}> */
    public array $analyticsRows = [];

    public ?string $message = null;

    public ?string $error = null;

    /** @var list<int> */
    public array $selectedCustomerIds = [];

    public bool $promoSmsModalOpen = false;

    public string $promoSmsMessage = '';

    public string $promoSmsCampaignId = '';

    public bool $promoSmsSending = false;

    public const SEGMENTS = [
        'customers' => 'Customers',
        'moderators' => 'Moderators',
        'resellers' => 'Resellers',
        'admins' => 'Admins',
    ];

    public function mount(string $segment = 'customers'): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->segment = array_key_exists($segment, self::SEGMENTS) ? $segment : 'customers';

        Role::findOrCreate('customers');
        Role::findOrCreate('moderator');
        Role::findOrCreate('reseller');
        Role::findOrCreate('admin');
    }

    public function switchSegment(string $segment): void
    {
        if (! array_key_exists($segment, self::SEGMENTS) || $this->segment === $segment) {
            return;
        }

        $this->segment = $segment;
        $this->resetPage();
        $this->selectedCustomerIds = [];
        $this->closePromoSmsModal();
        $this->closeAnalyticsModal(clearCache: true);
        $this->cityFilter = '';
        $this->cityNoneOnly = false;
        $this->ordersMin = '';
        $this->ordersMax = '';
        $this->categoryId = '';
        $this->message = null;
        $this->error = null;
        $this->js('history.replaceState({}, "", '.json_encode(route('admin.users.'.$segment)).')');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedCustomerIds = [];
    }

    public function updatedCityFilter(): void
    {
        $this->cityNoneOnly = false;
        $this->resetPage();
        $this->selectedCustomerIds = [];
    }

    public function updatedOrdersMin(): void
    {
        $this->resetPage();
        $this->selectedCustomerIds = [];
    }

    public function updatedOrdersMax(): void
    {
        $this->resetPage();
        $this->selectedCustomerIds = [];
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
        $this->selectedCustomerIds = [];
    }

    public function openAnalyticsReport(string $pill): void
    {
        if ($this->segment !== 'customers') {
            return;
        }

        if (! in_array($pill, ['city', 'orders', 'category'], true)) {
            return;
        }

        $this->analyticsPill = $pill;
        $this->analyticsModalOpen = true;
        $this->analyticsMinimized = false;
        $this->analyticsReportSearch = '';
        $this->analyticsShowAllRows = false;
        $this->error = null;

        if (isset($this->analyticsCache[$pill])) {
            $this->analyticsRows = $this->analyticsCache[$pill];
            $this->analyticsLoading = false;

            return;
        }

        $this->analyticsRows = [];
        $this->analyticsLoading = true;

        if (app()->runningUnitTests()) {
            $this->loadAnalyticsReport();

            return;
        }

        $this->js('requestAnimationFrame(() => $wire.loadAnalyticsReport())');
    }

    public function loadAnalyticsReport(?CustomerAnalyticsService $analytics = null): void
    {
        if ($this->segment !== 'customers' || ! in_array($this->analyticsPill, ['city', 'orders', 'category'], true)) {
            $this->analyticsLoading = false;

            return;
        }

        $analytics ??= app(CustomerAnalyticsService::class);
        $rows = $analytics->reportFor($this->analyticsPill);
        $this->analyticsCache[$this->analyticsPill] = $rows;
        $this->analyticsRows = $rows;
        $this->analyticsLoading = false;
    }

    public function minimizeAnalyticsModal(): void
    {
        if (! $this->analyticsModalOpen) {
            return;
        }

        $this->analyticsModalOpen = false;
        $this->analyticsMinimized = true;
    }

    public function restoreAnalyticsModal(): void
    {
        if ($this->analyticsPill === '' || ! isset($this->analyticsCache[$this->analyticsPill])) {
            if ($this->analyticsPill !== '') {
                $this->openAnalyticsReport($this->analyticsPill);
            }

            return;
        }

        $this->analyticsRows = $this->analyticsCache[$this->analyticsPill];
        $this->analyticsLoading = false;
        $this->analyticsMinimized = false;
        $this->analyticsModalOpen = true;
    }

    public function closeAnalyticsModal(bool $clearCache = false): void
    {
        $this->analyticsModalOpen = false;
        $this->analyticsMinimized = false;
        $this->analyticsLoading = false;
        $this->analyticsReportSearch = '';
        $this->analyticsShowAllRows = false;
        $this->analyticsRows = [];

        if ($clearCache) {
            $this->analyticsCache = [];
            $this->analyticsPill = '';
        }
    }

    public function applyAnalyticsFilter(string $pill, string $key): void
    {
        if ($this->segment !== 'customers') {
            return;
        }

        $this->selectedCustomerIds = [];
        $this->resetPage();
        $this->error = null;

        if ($pill === 'city') {
            $this->ordersMin = '';
            $this->ordersMax = '';
            $this->categoryId = '';
            $this->analyticsPill = 'city';

            if ($key === CustomerAnalyticsService::NO_CITY_KEY) {
                $this->cityFilter = '';
                $this->cityNoneOnly = true;
            } else {
                $this->cityNoneOnly = false;
                $this->cityFilter = $key;
            }

            $this->minimizeAnalyticsModal();

            return;
        }

        if ($pill === 'orders') {
            $this->cityFilter = '';
            $this->cityNoneOnly = false;
            $this->categoryId = '';
            $this->analyticsPill = 'orders';

            if ($key === CustomerAnalyticsService::ORDERS_10_PLUS_KEY) {
                $this->ordersMin = '10';
                $this->ordersMax = '';
                $this->minimizeAnalyticsModal();

                return;
            }

            if (ctype_digit($key)) {
                $this->ordersMin = $key;
                $this->ordersMax = $key;
            }

            $this->minimizeAnalyticsModal();

            return;
        }

        if ($pill === 'category' && ctype_digit($key)) {
            $this->cityFilter = '';
            $this->cityNoneOnly = false;
            $this->ordersMin = '';
            $this->ordersMax = '';
            $this->categoryId = $key;
            $this->analyticsPill = 'category';
            $this->minimizeAnalyticsModal();
        }
    }

    public function clearAnalyticsFilters(): void
    {
        $this->cityFilter = '';
        $this->cityNoneOnly = false;
        $this->ordersMin = '';
        $this->ordersMax = '';
        $this->categoryId = '';
        $this->selectedCustomerIds = [];
        $this->resetPage();
    }

    public function exportFilteredCustomers(): mixed
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'customers') {
            abort(404);
        }

        $token = (string) Str::uuid();

        $payload = [
            'user_id' => (int) auth()->id(),
            'search' => $this->search,
            'cityFilter' => $this->cityFilter,
            'cityNoneOnly' => $this->cityNoneOnly,
            'ordersMin' => $this->ordersMin,
            'ordersMax' => $this->ordersMax,
            'categoryId' => $this->categoryId,
        ];

        // #region agent log
        $cacheKey = CustomerExportService::cacheKey($token);
        $exportUrl = route('admin.users.customers.export', ['token' => $token]);
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'B,D', 'location' => 'AdminUsers.php:exportFilteredCustomers', 'message' => 'caching export filters before redirect', 'data' => ['authId' => auth()->id(), 'token' => $token, 'cacheKey' => $cacheKey, 'cacheDriver' => config('cache.default'), 'filters' => $payload, 'exportUrl' => $exportUrl, 'segment' => $this->segment], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'B', 'location' => 'AdminUsers.php:exportFilteredCustomers:afterPut', 'message' => 'cache put completed; verifying read-back', 'data' => ['cacheHas' => Cache::has($cacheKey), 'cachePeekUserId' => Cache::get($cacheKey)['user_id'] ?? null], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        // Full-page download avoids Livewire base64-embedding the XLSX (empty/black error modal on large sets).
        return $this->redirect($exportUrl, navigate: false);
    }

    public function toggleCustomerSelection(int $userId): void
    {
        if ($this->segment !== 'customers') {
            return;
        }

        if (in_array($userId, $this->selectedCustomerIds, true)) {
            $this->selectedCustomerIds = array_values(array_filter(
                $this->selectedCustomerIds,
                fn (int $id): bool => $id !== $userId,
            ));

            return;
        }

        $this->selectedCustomerIds[] = $userId;
    }

    /**
     * @param  list<int>  $pageIds
     */
    public function selectAllOnPage(array $pageIds): void
    {
        if ($this->segment !== 'customers') {
            return;
        }

        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        $this->selectedCustomerIds = array_values(array_unique(array_merge(
            $this->selectedCustomerIds,
            $pageIds,
        )));
    }

    /**
     * @param  list<int>  $pageIds
     */
    public function toggleSelectAllOnPage(array $pageIds): void
    {
        if ($this->segment !== 'customers') {
            return;
        }

        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));

        if ($pageIds === []) {
            return;
        }

        $allOnPageSelected = collect($pageIds)->every(
            fn (int $id): bool => in_array($id, $this->selectedCustomerIds, true),
        );

        if ($allOnPageSelected) {
            $this->selectedCustomerIds = array_values(array_filter(
                $this->selectedCustomerIds,
                fn (int $id): bool => ! in_array($id, $pageIds, true),
            ));

            return;
        }

        $this->selectAllOnPage($pageIds);
    }

    public function selectNone(): void
    {
        $this->selectedCustomerIds = [];
    }

    public function openPromoSmsModal(): void
    {
        AdminAccess::ensureStaffAdmin();

        $selectedIds = array_values(array_unique(array_map('intval', $this->selectedCustomerIds)));
        $this->selectedCustomerIds = $selectedIds;

        if ($this->segment !== 'customers' || $selectedIds === []) {
            $this->error = 'Select at least one customer to send promotional SMS.';

            return;
        }

        $this->promoSmsModalOpen = true;
        $this->promoSmsSending = false;
        $this->promoSmsMessage = '';
        $this->promoSmsCampaignId = (string) (config('sms.mimsms.promotional_campaign_id') ?? '');
        $this->error = null;
        $this->message = null;
        $this->js('document.body.classList.add("overflow-hidden")');
    }

    public function closePromoSmsModal(): void
    {
        $this->promoSmsModalOpen = false;
        $this->promoSmsSending = false;
        $this->promoSmsMessage = '';
        $this->promoSmsCampaignId = '';
        $this->js('document.body.classList.remove("overflow-hidden")');
    }

    public function sendPromoSms(PromotionalSmsService $promotionalSms): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'customers' || ! $this->promoSmsModalOpen) {
            return;
        }

        $this->validate([
            'promoSmsMessage' => ['required', 'string', 'min:3', 'max:1000'],
            'promoSmsCampaignId' => ['nullable', 'string', 'max:100'],
            'selectedCustomerIds' => ['required', 'array', 'min:1'],
            'selectedCustomerIds.*' => ['integer'],
        ], [
            'promoSmsMessage.required' => 'Enter the promotional SMS message.',
            'selectedCustomerIds.required' => 'Select at least one customer.',
            'selectedCustomerIds.min' => 'Select at least one customer.',
        ]);

        $this->promoSmsSending = true;
        $this->error = null;
        $this->message = null;

        $customers = User::query()
            ->role('customers')
            ->whereIn('id', $this->selectedCustomerIds)
            ->orderBy('id')
            ->get(['id', 'name', 'phone']);

        $campaignId = trim($this->promoSmsCampaignId) !== '' ? trim($this->promoSmsCampaignId) : null;

        $result = $promotionalSms->sendToCustomers(
            $customers,
            trim($this->promoSmsMessage),
            $campaignId,
        );

        $this->promoSmsSending = false;

        $parts = ['Sent '.$result['sent'].' promotional SMS'];
        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'].' skipped (no phone)';
        }
        if ($result['failed'] > 0) {
            $parts[] = $result['failed'].' failed';
        }

        $this->message = implode('; ', $parts).'.';

        if ($result['errors'] !== []) {
            $this->error = implode(' ', $result['errors']);
        }

        if ($result['sent'] > 0) {
            $this->selectedCustomerIds = [];
            $this->closePromoSmsModal();
        }
    }

    public function toggleActive(int $userId): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->message = null;
        $this->error = null;

        $user = $this->findManagedUser($userId);

        if (! $user) {
            return;
        }

        if ((int) $user->id === (int) auth()->id()) {
            $this->error = 'You cannot deactivate your own account.';

            return;
        }

        if ($user->is_active && AdminAccess::wouldRemoveLastAdmin($user)) {
            $this->error = 'Cannot deactivate the only admin account.';

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        $this->message = $user->is_active ? 'User activated.' : 'User deactivated.';
    }

    public function delete(int $userId): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->message = null;
        $this->error = null;

        $user = $this->findManagedUser($userId);

        if (! $user) {
            return;
        }

        if ((int) $user->id === (int) auth()->id()) {
            $this->error = 'You cannot delete your own account.';

            return;
        }

        if (AdminAccess::wouldRemoveLastAdmin($user)) {
            $this->error = 'Cannot delete the only admin account.';

            return;
        }

        if ($user->orders()->exists()) {
            $this->error = 'Cannot delete “'.$user->name.'” while orders still reference them. Deactivate instead.';

            return;
        }

        $user->delete();
        $this->message = 'User deleted.';
        $this->selectedCustomerIds = array_values(array_filter(
            $this->selectedCustomerIds,
            fn (int $id): bool => $id !== $userId,
        ));
    }

    public function render()
    {
        $role = match ($this->segment) {
            'moderators' => 'moderator',
            'resellers' => 'reseller',
            'admins' => 'admin',
            default => 'customers',
        };

        $users = $this->managedUsersQuery($role)
            ->when($this->segment === 'customers', function (Builder $query) {
                $query->withCount([
                    'orders as orders_count' => fn ($q) => $q->where('status', '!=', Order::STATUS_DRAFT),
                ]);
            })
            ->orderByDesc('id')
            ->paginate(30);

        $pageIds = $users->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allOnPageSelected = $pageIds !== []
            && collect($pageIds)->every(fn (int $id): bool => in_array($id, $this->selectedCustomerIds, true));

        $visibleAnalyticsRows = $this->visibleAnalyticsRows();

        return view('livewire.admin.admin-users', [
            'users' => $users,
            'segments' => self::SEGMENTS,
            'segmentLabel' => self::SEGMENTS[$this->segment],
            'roleName' => $role,
            'createLabel' => match ($this->segment) {
                'moderators' => 'Create Moderator',
                'resellers' => 'Create Reseller',
                'admins' => 'Create Admin',
                default => 'Create Customer',
            },
            'pageCustomerIds' => $pageIds,
            'allOnPageSelected' => $allOnPageSelected,
            'visibleAnalyticsRows' => $visibleAnalyticsRows,
            'analyticsFilteredTotal' => $this->filteredAnalyticsRows()->count(),
            'analyticsTitle' => match ($this->analyticsPill) {
                'city' => 'Customers by city',
                'orders' => 'Customers by lifetime orders',
                'category' => 'Customers by category ordered',
                default => 'Customer report',
            },
            'activeFilterSummary' => $this->activeFilterSummary(),
        ])->title(self::SEGMENTS[$this->segment]);
    }

    private function managedUsersQuery(string $role): Builder
    {
        return User::query()
            ->role($role)
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.$this->search.'%';
                $query->where(function (Builder $q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);

                    if ($this->segment === 'customers') {
                        $q->orWhere(function (Builder $cityQuery) use ($term) {
                            $this->applyCityMatch($cityQuery, $term);
                        });
                    }
                });
            })
            ->when($this->segment === 'customers', function (Builder $query) {
                $this->applyCustomerAnalyticsFilters($query);
            });
    }

    /**
     * @return Collection<int, array{key: string, label: string, count: int}>
     */
    private function filteredAnalyticsRows()
    {
        $term = mb_strtolower(trim($this->analyticsReportSearch));

        return collect($this->analyticsRows)
            ->when($term !== '', fn ($rows) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['label']), $term),
            ))
            ->values();
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function visibleAnalyticsRows(): array
    {
        $rows = $this->filteredAnalyticsRows();

        if (! $this->analyticsShowAllRows && $this->analyticsReportSearch === '') {
            $rows = $rows->take(20);
        }

        return $rows->all();
    }

    private function applyCustomerAnalyticsFilters(Builder $query): void
    {
        if ($this->cityNoneOnly) {
            $query->whereDoesntHave('orders', function (Builder $orders) {
                $orders->where('status', '!=', Order::STATUS_DRAFT)
                    ->whereNotNull('city')
                    ->where('city', '!=', '');
            })->whereDoesntHave('addresses', function (Builder $addresses) {
                $addresses->where(function (Builder $a) {
                    $a->where(function (Builder $b) {
                        $b->whereNotNull('city')->where('city', '!=', '');
                    })->orWhereHas('city', fn (Builder $c) => $c->whereNotNull('name')->where('name', '!=', ''));
                });
            });
        } elseif ($this->cityFilter !== '') {
            $term = '%'.$this->cityFilter.'%';
            $query->where(function (Builder $cityQuery) use ($term) {
                $this->applyCityMatch($cityQuery, $term);
            });
        }

        $ordersMin = $this->normalizedOrderBound($this->ordersMin);
        $ordersMax = $this->normalizedOrderBound($this->ordersMax);

        if ($ordersMin !== null && $ordersMax !== null && $ordersMin > $ordersMax) {
            [$ordersMin, $ordersMax] = [$ordersMax, $ordersMin];
        }

        if ($ordersMin !== null || $ordersMax !== null) {
            $query->where(function (Builder $q) use ($ordersMin, $ordersMax) {
                $lifetime = '(select count(*) from orders where orders.user_id = users.id and orders.status != ?)';

                if ($ordersMin !== null) {
                    $q->whereRaw($lifetime.' >= ?', [Order::STATUS_DRAFT, $ordersMin]);
                }
                if ($ordersMax !== null) {
                    $q->whereRaw($lifetime.' <= ?', [Order::STATUS_DRAFT, $ordersMax]);
                }
            });
        }

        if ($this->categoryId !== '' && ctype_digit($this->categoryId)) {
            $categoryId = (int) $this->categoryId;
            $query->whereHas('orders', function (Builder $orders) use ($categoryId) {
                $orders->where('status', '!=', Order::STATUS_DRAFT)
                    ->whereHas('items', function (Builder $items) use ($categoryId) {
                        $items->whereHas('product', fn (Builder $product) => $product->where('category_id', $categoryId));
                    });
            });
        }
    }

    /**
     * @return list<string>
     */
    private function activeFilterSummary(): array
    {
        if ($this->segment !== 'customers') {
            return [];
        }

        $parts = [];

        if ($this->cityNoneOnly) {
            $parts[] = 'City: (no city)';
        } elseif ($this->cityFilter !== '') {
            $parts[] = 'City: '.$this->cityFilter;
        }

        if ($this->ordersMin !== '' || $this->ordersMax !== '') {
            if ($this->ordersMin !== '' && $this->ordersMax !== '' && $this->ordersMin === $this->ordersMax) {
                $parts[] = 'Orders: '.$this->ordersMin;
            } elseif ($this->ordersMin !== '' && $this->ordersMax === '') {
                $parts[] = 'Orders: '.$this->ordersMin.'+';
            } else {
                $parts[] = 'Orders: '.($this->ordersMin !== '' ? $this->ordersMin : '0')
                    .'-'.($this->ordersMax !== '' ? $this->ordersMax : '∞');
            }
        }

        if ($this->categoryId !== '' && ctype_digit($this->categoryId)) {
            $name = DB::table('categories')->where('id', (int) $this->categoryId)->value('name');
            $parts[] = 'Category: '.($name ?: '#'.$this->categoryId);
        }

        return $parts;
    }

    private function applyCityMatch(Builder $query, string $term): void
    {
        $query->whereHas('orders', function (Builder $orders) use ($term) {
            $orders->where('city', 'like', $term);
        })->orWhereHas('addresses', function (Builder $addresses) use ($term) {
            $addresses->where(function (Builder $a) use ($term) {
                $a->where('city', 'like', $term)
                    ->orWhereHas('city', function (Builder $city) use ($term) {
                        $city->where('name', 'like', $term);
                    });
            });
        });
    }

    private function normalizedOrderBound(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return max(0, (int) $trimmed);
    }

    private function findManagedUser(int $userId): ?User
    {
        $user = User::query()->find($userId);

        if (! $user) {
            $this->error = 'User not found.';

            return null;
        }

        if ($this->segment === 'admins') {
            if ($user->hasRole('dev') || ! $user->hasRole('admin')) {
                $this->error = 'That user cannot be managed here.';

                return null;
            }

            return $user;
        }

        if ($user->hasAnyRole(['admin', 'dev'])) {
            $this->error = 'That user cannot be managed here.';

            return null;
        }

        $expectedRole = match ($this->segment) {
            'moderators' => 'moderator',
            'resellers' => 'reseller',
            default => 'customers',
        };

        if (! $user->hasRole($expectedRole)) {
            $this->error = 'User is not in this list.';

            return null;
        }

        return $user;
    }
}
