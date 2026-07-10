<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Rules\BangladeshMobile;
use App\Support\AdminAccess;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.admin')]
class AdminUserEdit extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'customers';

    public bool $is_active = true;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?User $user = null): void
    {
        AdminAccess::ensureCanManageOrders();

        Role::findOrCreate('customers');
        Role::findOrCreate('moderator');

        if ($user?->exists) {
            if ($user->hasAnyRole(['admin', 'dev'])) {
                abort(403, 'Admin and developer accounts cannot be edited here.');
            }

            $this->user = $user;
            $this->name = $user->name;
            $this->phone = (string) $user->phone;
            $this->email = (string) ($user->email ?? '');
            $this->is_active = (bool) $user->is_active;
            $this->role = $user->hasRole('moderator') ? 'moderator' : 'customers';

            return;
        }

        $requested = request()->query('role');
        if (in_array($requested, ['customers', 'moderator'], true)) {
            $this->role = $requested;
        }
    }

    public function title(): string
    {
        return $this->user ? 'Edit '.$this->user->name : 'Create User';
    }

    public function save(): void
    {
        AdminAccess::ensureCanManageOrders();
        $this->message = null;
        $this->error = null;

        $phoneUnique = Rule::unique('users', 'phone');
        $emailUnique = Rule::unique('users', 'email');

        if ($this->user) {
            $phoneUnique = $phoneUnique->ignore($this->user->id);
            $emailUnique = $emailUnique->ignore($this->user->id);
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new BangladeshMobile, $phoneUnique],
            'email' => ['nullable', 'email', 'max:120', $emailUnique],
            'role' => ['required', 'in:customers,moderator'],
            'is_active' => ['boolean'],
        ];

        if ($this->user === null || $this->password !== '') {
            $rules['password'] = [
                $this->user === null ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ];
        }

        $validated = $this->validate($rules);

        if ($this->user && (int) $this->user->id === (int) auth()->id() && ! $validated['is_active']) {
            $this->addError('is_active', 'You cannot deactivate your own account.');

            return;
        }

        $payload = [
            'name' => $validated['name'],
            'phone' => PhoneNumber::display($validated['phone']),
            'email' => $validated['email'] !== '' ? $validated['email'] : null,
            'is_active' => $validated['is_active'],
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        if ($this->user) {
            $this->user->update($payload);
            $this->user->syncRoles([$validated['role']]);
            $this->user = $this->user->fresh();
            $this->password = '';
            $this->password_confirmation = '';
            $this->message = 'User saved.';
        } else {
            $user = User::query()->create($payload);
            $user->syncRoles([$validated['role']]);
            $this->redirect(route('admin.users.edit', $user), navigate: true);
        }
    }

    public function delete(): void
    {
        AdminAccess::ensureCanManageOrders();

        if (! $this->user) {
            return;
        }

        if ((int) $this->user->id === (int) auth()->id()) {
            $this->error = 'You cannot delete your own account.';

            return;
        }

        if ($this->user->hasAnyRole(['admin', 'dev'])) {
            abort(403);
        }

        if ($this->user->orders()->exists()) {
            $this->error = 'Cannot delete while orders still reference this user. Deactivate instead.';

            return;
        }

        $role = $this->user->hasRole('moderator') ? 'moderators' : 'customers';
        $this->user->delete();
        $this->redirect(route('admin.users.'.$role), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-user-edit', [
            'canDelete' => $this->user
                && (int) $this->user->id !== (int) auth()->id()
                && $this->user->orders()->doesntExist(),
        ])->title($this->title());
    }
}
