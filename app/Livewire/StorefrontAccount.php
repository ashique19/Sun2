<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('My Account - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontAccount extends Component
{
    public function render()
    {
        $user = auth()->user();
        $recentOrders = $user->orders()->latest('placed_at')->latest('id')->limit(5)->get();

        return view('livewire.storefront-account', [
            'user' => $user,
            'recentOrders' => $recentOrders,
        ]);
    }
}
