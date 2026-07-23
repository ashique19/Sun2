<?php

namespace App\Livewire\Reseller;

use App\Models\Order;
use App\Support\ResellerAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder: full create-order UI lands in phase 3 (see docs/RESELLER-PLAN.md).
 */
#[Layout('components.layouts.reseller')]
class ResellerOrderCreate extends Component
{
    public function mount(): void
    {
        ResellerAccess::ensureReseller();
    }

    public function title(): string
    {
        return 'Create order';
    }

    public function render()
    {
        return view('livewire.reseller.reseller-order-create');
    }
}
