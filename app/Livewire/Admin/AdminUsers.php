<?php

namespace App\Livewire\Admin;

use App\Models\User;
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

    public const SEGMENTS = [
        'customers' => 'Customers',
        'moderators' => 'Moderators',
    ];

    public function mount(string $segment = 'customers'): void
    {
        AdminAccess::ensureCanManageOrders();

        $this->segment = array_key_exists($segment, self::SEGMENTS) ? $segment : 'customers';

        Role::findOrCreate('customers');
        Role::findOrCreate('moderator');
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

    public function toggleActive(int $userId): void
    {
        AdminAccess::ensureCanManageOrders();
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

        $user->update(['is_active' => ! $user->is_active]);
        $this->message = $user->is_active ? 'User activated.' : 'User deactivated.';
    }

    public function delete(int $userId): void
    {
        AdminAccess::ensureCanManageOrders();
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

        if ($user->orders()->exists()) {
            $this->error = 'Cannot delete “'.$user->name.'” while orders still reference them. Deactivate instead.';

            return;
        }

        $user->delete();
        $this->message = 'User deleted.';
    }

    public function render()
    {
        $role = $this->segment === 'moderators' ? 'moderator' : 'customers';

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

        if (! $user || $user->hasAnyRole(['admin', 'dev'])) {
            $this->error = 'That user cannot be managed here.';

            return null;
        }

        $expectedRole = $this->segment === 'moderators' ? 'moderator' : 'customers';

        if (! $user->hasRole($expectedRole)) {
            $this->error = 'User is not in this list.';

            return null;
        }

        return $user;
    }
}
