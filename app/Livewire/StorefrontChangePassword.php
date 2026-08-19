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
        $user = auth()->user();

        if ($user->password === null) {
            $this->setInitialPassword();

            return;
        }

        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($this->current_password, (string) $user->password)) {
            $this->addError('current_password', 'Current password is incorrect.');

            return;
        }

        $user->update(['password' => $this->password]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->statusMessage = __('storefront.password_changed');
    }

    public function setInitialPassword(): void
    {
        $user = auth()->user();

        if ($user->password !== null) {
            return;
        }

        $this->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $this->password]);

        $this->reset(['password', 'password_confirmation']);
        $this->statusMessage = __('storefront.password_set_success');
    }

    public function render()
    {
        return view('livewire.storefront-change-password', [
            'needsPassword' => auth()->user()->password === null,
        ]);
    }
}
