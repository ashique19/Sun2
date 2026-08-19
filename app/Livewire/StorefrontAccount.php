<?php

namespace App\Livewire;

use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('My Account - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontAccount extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public ?string $passwordStatusMessage = null;

    public function setPassword(): void
    {
        $user = auth()->user();

        if ($user->password) {
            return;
        }

        $this->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $this->password]);

        $this->reset(['password', 'password_confirmation']);
        $this->passwordStatusMessage = __('storefront.password_set_success');
    }

    public function render()
    {
        $user = auth()->user();
        $activeOrders = $user->orders()
            ->activeForCustomer()
            ->with(['courier:id,name'])
            ->latest('placed_at')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('livewire.storefront-account', [
            'user' => $user,
            'activeOrders' => $activeOrders,
            'needsPassword' => $user->password === null,
        ]);
    }
}
