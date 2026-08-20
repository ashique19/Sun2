<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\CustomerDuplicateMergeService;
use App\Services\Sms\PromotionalSmsService;
use App\Support\AdminAccess;
use Illuminate\Database\Eloquent\Builder;
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

    public ?string $message = null;

    public ?string $error = null;

    public bool $mergeDuplicatesModalOpen = false;

    public bool $mergeDuplicatesRunning = false;

    public int $mergeDuplicatesRemaining = 0;

    public int $mergeDuplicatesMergedGroups = 0;

    public int $mergeDuplicatesDeletedUsers = 0;

    public int $mergeDuplicatesReassignedOrders = 0;

    public ?string $mergeDuplicatesMessage = null;

    /** @var list<string> */
    public array $mergeDuplicatesSamples = [];

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
        $this->resetPage();
        $this->selectedCustomerIds = [];
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

        if ($this->segment !== 'customers' || $this->selectedCustomerIds === []) {
            $this->error = 'Select at least one customer to send promotional SMS.';

            return;
        }

        $this->promoSmsModalOpen = true;
        $this->promoSmsSending = false;
        $this->promoSmsMessage = '';
        $this->promoSmsCampaignId = (string) (config('sms.mimsms.promotional_campaign_id') ?? '');
        $this->error = null;
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

    public function openMergeDuplicatesModal(CustomerDuplicateMergeService $merger): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->segment !== 'customers') {
            return;
        }

        $this->mergeDuplicatesModalOpen = true;
        $this->mergeDuplicatesRunning = false;
        $this->mergeDuplicatesMergedGroups = 0;
        $this->mergeDuplicatesDeletedUsers = 0;
        $this->mergeDuplicatesReassignedOrders = 0;
        $this->mergeDuplicatesSamples = [];
        $this->mergeDuplicatesRemaining = $merger->duplicateGroupCount();
        $this->mergeDuplicatesMessage = $this->mergeDuplicatesRemaining === 0
            ? 'No duplicate customer phones found.'
            : $this->mergeDuplicatesRemaining.' phone number(s) have more than one customer profile.';
        $this->js('document.body.classList.add("overflow-hidden")');
    }

    public function closeMergeDuplicatesModal(): void
    {
        $this->mergeDuplicatesModalOpen = false;
        $this->mergeDuplicatesRunning = false;
        $this->mergeDuplicatesMessage = null;
        $this->mergeDuplicatesSamples = [];
        $this->js('document.body.classList.remove("overflow-hidden")');
    }

    public function runMergeDuplicatesBatch(CustomerDuplicateMergeService $merger): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->mergeDuplicatesModalOpen || $this->segment !== 'customers') {
            $this->mergeDuplicatesRunning = false;

            return;
        }

        $this->mergeDuplicatesRunning = true;

        $result = $merger->mergeNextBatch();

        $this->mergeDuplicatesMergedGroups += $result['merged_groups'];
        $this->mergeDuplicatesDeletedUsers += $result['deleted_users'];
        $this->mergeDuplicatesReassignedOrders += $result['reassigned_orders'];
        $this->mergeDuplicatesRemaining = $result['remaining_groups'];
        $this->mergeDuplicatesSamples = array_slice(array_merge(
            $this->mergeDuplicatesSamples,
            $result['samples'],
        ), 0, 12);

        if ($result['done']) {
            $this->mergeDuplicatesRunning = false;
            $this->mergeDuplicatesMessage = $this->mergeDuplicatesMergedGroups === 0
                ? 'No duplicate customer phones found.'
                : 'Merged '.$this->mergeDuplicatesMergedGroups.' phone group(s), removed '
                    .$this->mergeDuplicatesDeletedUsers.' older profile(s), reassigned '
                    .$this->mergeDuplicatesReassignedOrders.' order(s).';
            $this->message = $this->mergeDuplicatesMessage;

            return;
        }

        $this->mergeDuplicatesMessage = 'Merged '.$this->mergeDuplicatesMergedGroups.' group(s) so far. '
            .$this->mergeDuplicatesRemaining.' left…';

        if (app()->runningUnitTests()) {
            $this->runMergeDuplicatesBatch($merger);

            return;
        }

        $this->js('setTimeout(() => $wire.runMergeDuplicatesBatch(), 50)');
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

        $users = User::query()
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
            ->when($this->segment === 'customers' && $this->cityFilter !== '', function (Builder $query) {
                $term = '%'.$this->cityFilter.'%';
                $query->where(function (Builder $cityQuery) use ($term) {
                    $this->applyCityMatch($cityQuery, $term);
                });
            })
            ->orderByDesc('id')
            ->paginate(30);

        $pageIds = $users->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allOnPageSelected = $pageIds !== []
            && collect($pageIds)->every(fn (int $id): bool => in_array($id, $this->selectedCustomerIds, true));

        return view('livewire.admin.admin-users', [
            'users' => $users,
            'segments' => self::SEGMENTS,
            'segmentLabel' => self::SEGMENTS[$this->segment],
            'roleName' => $role,
            'pageCustomerIds' => $pageIds,
            'allOnPageSelected' => $allOnPageSelected,
        ])->title(self::SEGMENTS[$this->segment]);
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
