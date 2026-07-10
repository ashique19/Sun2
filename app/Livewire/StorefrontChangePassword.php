<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Change Password - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontChangePassword extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $statusMessage = null;

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = auth()->user();

        if (! $user->password || ! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'Current password is incorrect.');

            return;
        }

        $user->update(['password' => $this->password]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->statusMessage = 'Password changed successfully.';
    }

    public function render()
    {
        return view('livewire.storefront-change-password');
    }
}
