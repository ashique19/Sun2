<?php

namespace App\Livewire\Admin;

use App\Models\Material;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Materials')]
#[Layout('components.layouts.admin')]
class AdminMaterials extends Component
{
    public ?string $error = null;

    public function delete(int $materialId): void
    {
        $this->error = null;

        $material = Material::query()->withCount('products')->findOrFail($materialId);

        if ($material->products_count > 0) {
            $this->error = 'Cannot delete “'.$material->name.'” while products still use it.';

            return;
        }

        $material->delete();
    }

    public function render()
    {
        return view('livewire.admin.admin-materials', [
            'materials' => Material::query()
                ->withCount('products')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
