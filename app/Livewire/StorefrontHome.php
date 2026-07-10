<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\HeroSlide;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sundoritoma - Traditional & Imitation Jewelry')]
#[Layout('components.layouts.app')]
class StorefrontHome extends Component
{
    public function render()
    {
        $categories = Category::query()
            ->withCount(['products' => fn ($q) => $q->where('is_published', true)])
            ->where('is_active', true)
            ->where('is_homepage', true)
            ->orderBy('display_order')
            ->get();

        return view('livewire.storefront-home', [
            'categories' => $categories,
            'heroSlides' => HeroSlide::query()
                ->published()
                ->orderBy('display_order')
                ->get(),
        ]);
    }
}
