<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Rules\BangladeshMobile;
use App\Services\Reseller\ResellerWalletService;
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

    public string $payoutAmount = '';

    public string $payoutNote = '';

    public function mount(?User $user = null): void
    {
        AdminAccess::ensureStaffAdmin();

        Role::findOrCreate('customers');
        Role::findOrCreate('moderator');
        Role::findOrCreate('reseller');

        if ($user?->exists) {
            if ($user->hasAnyRole(['admin', 'dev'])) {
                abort(403, 'Admin and developer accounts cannot be edited here.');
            }

            $this->user = $user;
            $this->name = $user->name;
            $this->phone = (string) $user->phone;
            $this->email = (string) ($user->email ?? '');
            $this->is_active = (bool) $user->is_active;
            $this->role = $user->hasRole('moderator')
                ? 'moderator'
                : ($user->hasRole('reseller') ? 'reseller' : 'customers');

            return;
        }

        $requested = request()->query('role');
        if (in_array($requested, ['customers', 'moderator', 'reseller'], true)) {
            $this->role = $requested;
        }
    }

    public function title(): string
    {
        return $this->user ? 'Edit '.$this->user->name : 'Create User';
    }

    public function save(): void
    {
        AdminAccess::ensureStaffAdmin();
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
            'role' => ['required', 'in:customers,moderator,reseller'],
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
        AdminAccess::ensureStaffAdmin();

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

        $role = $this->user->hasRole('moderator')
            ? 'moderators'
            : ($this->user->hasRole('reseller') ? 'resellers' : 'customers');
        $this->user->delete();
        $this->redirect(route('admin.users.'.$role), navigate: true);
    }

    public function recordPayout(ResellerWalletService $wallet): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->message = null;
        $this->error = null;

        if (! $this->user || ! $this->user->hasRole('reseller')) {
            $this->error = 'Payouts can only be recorded for reseller accounts.';

            return;
        }

        $validated = $this->validate([
            'payoutAmount' => ['required', 'numeric', 'min:1', 'integer'],
            'payoutNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $wallet->recordPayout(
                userId: (int) $this->user->id,
                amount: (float) (int) $validated['payoutAmount'],
                note: trim((string) ($validated['payoutNote'] ?? '')) ?: null,
                createdBy: (int) auth()->id(),
            );

            $this->user = $this->user->fresh();
            $this->payoutAmount = '';
            $this->payoutNote = '';
            $this->message = 'Payout recorded.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.admin.admin-user-edit', [
            'canDelete' => $this->user
                && (int) $this->user->id !== (int) auth()->id()
                && $this->user->orders()->doesntExist(),
            'isReseller' => $this->user?->hasRole('reseller') ?? false,
            'resellerBalance' => $this->user ? (float) $this->user->reseller_balance : 0.0,
        ])->title($this->title());
    }
}
