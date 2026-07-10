<?php

namespace App\Livewire\Admin;

use App\Models\HeroSlide;
use App\Services\Admin\HeroSlideImageService;
use App\Support\StorefrontAssets;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class AdminHeroSlideEdit extends Component
{
    use WithFileUploads;

    public ?HeroSlide $slide = null;

    public string $titleText = '';

    public string $subtitle = '';

    public string $image = '';

    public string $link_url = '';

    public string $link_label = '';

    public int $display_order = 0;

    public bool $is_published = true;

    /** @var mixed */
    public $imageUpload = null;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?HeroSlide $slide = null): void
    {
        if ($slide?->exists) {
            $this->slide = $slide;
            $this->titleText = $slide->title;
            $this->subtitle = (string) ($slide->subtitle ?? '');
            $this->image = (string) $slide->image;
            $this->link_url = (string) ($slide->link_url ?? '');
            $this->link_label = (string) ($slide->link_label ?? '');
            $this->display_order = (int) $slide->display_order;
            $this->is_published = (bool) $slide->is_published;
        } else {
            $this->display_order = (int) (HeroSlide::query()->max('display_order') ?? 0) + 1;
        }
    }

    public function title(): string
    {
        return $this->slide ? 'Edit hero slide' : 'Create Hero Slide';
    }

    public function save(HeroSlideImageService $images): void
    {
        $this->message = null;
        $this->error = null;

        $isCreate = $this->slide === null;

        $rules = [
            'titleText' => ['required', 'string', 'max:190'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'max:255'],
            'link_label' => ['nullable', 'string', 'max:80'],
            'display_order' => ['integer', 'min:0', 'max:32767'],
            'is_published' => ['boolean'],
            'imageUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ];

        if ($isCreate && ! $this->imageUpload) {
            $rules['imageUpload'] = ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'];
        }

        $validated = $this->validate($rules);

        $payload = [
            'title' => $validated['titleText'],
            'subtitle' => $validated['subtitle'] !== '' ? $validated['subtitle'] : null,
            'link_url' => $validated['link_url'] !== '' ? $validated['link_url'] : null,
            'link_label' => $validated['link_label'] !== '' ? $validated['link_label'] : null,
            'display_order' => $validated['display_order'],
            'is_published' => $validated['is_published'],
        ];

        if ($this->slide) {
            $this->slide->update($payload);
        } else {
            $this->slide = HeroSlide::query()->create(array_merge($payload, [
                'image' => '/img/hero/pending.jpg',
            ]));
        }

        if ($this->imageUpload) {
            try {
                $this->image = $images->store($this->slide->fresh(), $this->imageUpload);
                $this->imageUpload = null;
            } catch (\Throwable $e) {
                if ($isCreate && $this->slide) {
                    $this->slide->delete();
                    $this->slide = null;
                }

                $this->error = 'Could not save the hero image. Please try another file.';

                return;
            }
        }

        $this->slide = $this->slide->fresh();
        $this->image = (string) $this->slide->image;

        if ($isCreate) {
            $this->redirect(route('admin.hero-slides.edit', $this->slide), navigate: true);

            return;
        }

        $this->message = 'Hero slide saved.';
    }

    public function delete(HeroSlideImageService $images): void
    {
        if (! $this->slide) {
            return;
        }

        $image = $this->slide->image;
        $this->slide->delete();
        $images->deleteLocalFile($image);

        $this->redirect(route('admin.hero-slides'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-hero-slide-edit', [
            'imagePreviewUrl' => $this->imagePreviewUrl(),
        ])->title($this->title());
    }

    private function imagePreviewUrl(): ?string
    {
        if ($this->imageUpload) {
            return $this->imageUpload->temporaryUrl();
        }

        return StorefrontAssets::url($this->image ?: null);
    }
}
