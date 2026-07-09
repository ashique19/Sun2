<?php

namespace App\Livewire;

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sundoritoma - Traditional & Imitation Jewelry')]
#[Layout('components.layouts.app')]
class StorefrontHome extends Component
{
    public string $search = '';

    public function render()
    {
        $categories = Category::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('display_order')
            ->get();

        return view('livewire.storefront-home', [
            'categories' => $categories,
        ]);
    }
}
