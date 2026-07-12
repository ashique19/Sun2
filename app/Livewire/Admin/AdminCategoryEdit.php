<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Services\Admin\CategoryImageService;
use App\Support\StorefrontAssets;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class AdminCategoryEdit extends Component
{
    use WithFileUploads;

    public ?Category $category = null;

    public string $name = '';

    public string $slug = '';

    public string $headline = '';

    public string $summary = '';

    public ?string $thumb_image = null;

    public int $display_order = 0;

    public bool $is_active = true;

    public bool $is_homepage = true;

    /** @var mixed */
    public $thumbUpload = null;

    public bool $removeThumb = false;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?Category $category = null): void
    {
        if ($category?->exists) {
            $this->category = $category;
            $this->name = $category->name;
            $this->slug = $category->slug;
            $this->headline = (string) ($category->headline ?? '');
            $this->summary = (string) ($category->summary ?? '');
            $this->thumb_image = $category->thumb_image;
            $this->display_order = (int) $category->display_order;
            $this->is_active = (bool) $category->is_active;
            $this->is_homepage = (bool) $category->is_homepage;
        }
    }

    public function title(): string
    {
        return $this->category ? 'Edit '.$this->category->name : 'Create Category';
    }

    public function updatedName(string $value): void
    {
        if ($this->category) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function clearThumbnail(): void
    {
        $this->thumbUpload = null;
        $this->removeThumb = true;
    }

    public function save(CategoryImageService $images): void
    {
        $this->message = null;
        $this->error = null;

        $isCreate = $this->category === null;

        $slugUnique = $this->category
            ? 'unique:categories,slug,'.$this->category->id
            : 'unique:categories,slug';

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', $slugUnique],
            'headline' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'display_order' => ['integer', 'min:0', 'max:32767'],
            'is_active' => ['boolean'],
            'is_homepage' => ['boolean'],
            'thumbUpload' => \App\Support\Fileinfo::storedImageRules(5120, required: false),
        ]);

        if ($validated['slug'] === '') {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $payload = [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'headline' => $validated['headline'] ?: null,
            'summary' => $validated['summary'] ?: null,
            'display_order' => $validated['display_order'],
            'is_active' => $validated['is_active'],
            'is_homepage' => $validated['is_homepage'],
        ];

        if ($this->category) {
            $this->category->update($payload);
        } else {
            $this->category = Category::query()->create($payload);
        }

        if ($this->removeThumb && ! $this->thumbUpload) {
            $images->clear($this->category);
            $this->thumb_image = null;
            $this->removeThumb = false;
        }

        if ($this->thumbUpload) {
            $this->thumb_image = $images->store($this->category->fresh(), $this->thumbUpload);
            $this->thumbUpload = null;
            $this->removeThumb = false;
        }

        $this->category = $this->category->fresh();
        $this->thumb_image = $this->category->thumb_image;

        if ($isCreate) {
            $this->redirect(route('admin.categories.edit', $this->category), navigate: true);

            return;
        }

        $this->message = 'Category saved.';
    }

    public function delete(CategoryImageService $images): void
    {
        $this->error = null;
        $this->message = null;

        if (! $this->category) {
            return;
        }

        $this->category->loadCount('products');

        if ($this->category->products_count > 0) {
            $this->error = 'Cannot delete a category that still has products.';

            return;
        }

        $thumb = $this->category->thumb_image;
        $this->category->delete();
        $images->deleteLocalFile($thumb);

        $this->redirect(route('admin.categories'), navigate: true);
    }

    public function render()
    {
        $canDelete = $this->category
            && $this->category->products()->doesntExist();

        return view('livewire.admin.admin-category-edit', [
            'thumbPreviewUrl' => $this->thumbPreviewUrl(),
            'canDelete' => $canDelete,
        ])->title($this->title());
    }

    private function thumbPreviewUrl(): ?string
    {
        if ($this->thumbUpload) {
            return $this->thumbUpload->temporaryUrl();
        }

        if ($this->removeThumb) {
            return null;
        }

        return StorefrontAssets::url($this->thumb_image);
    }
}
