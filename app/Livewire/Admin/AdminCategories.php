<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Services\Admin\CategoryImageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Categories')]
#[Layout('components.layouts.admin')]
class AdminCategories extends Component
{
    public ?string $error = null;

    public function delete(int $categoryId, CategoryImageService $images): void
    {
        $this->error = null;

        $category = Category::query()->withCount('products')->findOrFail($categoryId);

        if ($category->products_count > 0) {
            $this->error = 'Cannot delete “'.$category->name.'” while it still has products.';

            return;
        }

        $thumb = $category->thumb_image;
        $category->delete();
        $images->deleteLocalFile($thumb);
    }

    public function render()
    {
        return view('livewire.admin.admin-categories', [
            'categories' => Category::query()
                ->withCount('products')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
