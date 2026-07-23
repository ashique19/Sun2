<?php

namespace App\Livewire\Reseller;

use App\Models\ResellerWalletEntry;
use App\Support\ResellerAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.reseller')]
class ResellerWallet extends Component
{
    use WithPagination;

    public function mount(): void
    {
        ResellerAccess::ensureReseller();
    }

    public function title(): string
    {
        return 'Account balance';
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.reseller.reseller-wallet', [
            'balance' => (float) $user->reseller_balance,
            'entries' => ResellerWalletEntry::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->paginate(30),
        ]);
    }
}
