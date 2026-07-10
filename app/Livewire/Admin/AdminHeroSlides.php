<?php

namespace App\Livewire\Admin;

use App\Models\HeroSlide;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Hero Slides')]
#[Layout('components.layouts.admin')]
class AdminHeroSlides extends Component
{
    public function delete(int $slideId): void
    {
        $slide = HeroSlide::query()->find($slideId);

        if (! $slide) {
            return;
        }

        $image = $slide->image;
        $slide->delete();
        app(\App\Services\Admin\HeroSlideImageService::class)->deleteLocalFile($image);
    }

    public function togglePublished(int $slideId): void
    {
        $slide = HeroSlide::query()->findOrFail($slideId);
        $slide->update(['is_published' => ! $slide->is_published]);
    }

    public function render()
    {
        return view('livewire.admin.admin-hero-slides', [
            'slides' => HeroSlide::query()
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
