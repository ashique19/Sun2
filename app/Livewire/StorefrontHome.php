<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\SocialPost;
use App\Support\JsonLd;
use App\Support\Seo;
use App\Support\StorefrontAssets;
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

        $latestSocialPosts = SocialPost::query()
            ->onHomepage()
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'body', 'thumbnail_path', 'created_at', 'layout']);

        $heroSlides = HeroSlide::query()
            ->published()
            ->orderBy('display_order')
            ->get();

        $firstHero = $heroSlides->first();
        $heroPreload = $firstHero
            ? (StorefrontAssets::mediumUrl($firstHero->image)
        ?? StorefrontAssets::url($firstHero->image))
            : null;
        $heroPreloadSrcset = $firstHero
            ? StorefrontAssets::srcset($firstHero->image, [
                'xs' => 480,
                'sm' => 800,
                'md' => 1200,
            ])
            : null;

        return view('livewire.storefront-home', [
            'categories' => $categories,
            'heroSlides' => $heroSlides,
            'latestSocialPosts' => $latestSocialPosts,
        ])
            ->title(config('seo.default_title'))
            ->layoutData([
                'seoDescription' => Seo::description(null),
                'seoCanonical' => route('home'),
                'seoType' => 'website',
                'seoImage' => $heroPreload ?: '/img/settings/logo.png',
                'seoJsonLd' => [JsonLd::website()],
                'seoPreloadImage' => $heroPreload,
                'seoPreloadImageSrcset' => $heroPreloadSrcset,
            ]);
    }
}
