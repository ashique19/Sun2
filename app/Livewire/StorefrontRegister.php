<?php

namespace App\Livewire;

use App\Models\User;
use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Title('Create Account - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontRegister extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new BangladeshMobile, 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'phone' => PhoneNumber::display($validated['phone']),
            'email' => $validated['email'] ?: null,
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        Role::findOrCreate('customers');
        $user->assignRole('customers');

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('account'), navigate: true);
    }

    public function render()
    {
        return view('livewire.storefront-register');
    }
}
