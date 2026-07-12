<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\HeroSlide;
use App\Support\Seo;
use Livewire\Attributes\Layout;
use Livewire\Component;

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
        ])
            ->title(config('seo.default_title'))
            ->layoutData([
                'seoDescription' => Seo::description(null),
                'seoCanonical' => route('home'),
                'seoType' => 'website',
            ]);
    }
}
