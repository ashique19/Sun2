<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Admin\CustomerDuplicateMergeService;
use App\Support\AdminAccess;
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
        $this->message = null;
        $this->error = null;
        $this->js('history.replaceState({}, "", '.json_encode(route('admin.users.'.$segment)).')');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderByDesc('id')
            ->paginate(30);

        return view('livewire.admin.admin-users', [
            'users' => $users,
            'segments' => self::SEGMENTS,
            'segmentLabel' => self::SEGMENTS[$this->segment],
            'roleName' => $role,
        ])->title(self::SEGMENTS[$this->segment]);
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
