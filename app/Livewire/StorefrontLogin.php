<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Login - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontLogin extends Component
{
    public string $identifier = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findByLoginIdentifier($this->identifier);

        if (! $user || ! $user->password || ! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'identifier' => 'This account is inactive. Please contact support.',
            ]);
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        if (AdminAccess::isModeratorOnly($user)) {
            $this->redirect(route('admin.orders.new'), navigate: true);

            return;
        }

        $this->redirectIntended(route('account'), navigate: true);
    }

    public function render()
    {
        return view('livewire.storefront-login');
    }
}
