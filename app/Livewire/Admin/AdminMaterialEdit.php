<?php

namespace App\Livewire\Admin;

use App\Models\Material;
use App\Services\Admin\ProductUnitCostService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminMaterialEdit extends Component
{
    public ?Material $material = null;

    public string $name = '';

    public string $sku = '';

    public string $unit = 'pcs';

    public string $unit_cost = '0';

    public string $stock_quantity = '0';

    public string $notes = '';

    public string $receive_quantity = '';

    public string $receive_total_cost = '';

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?Material $material = null): void
    {
        if ($material?->exists) {
            $this->material = $material;
            $this->name = $material->name;
            $this->sku = (string) ($material->sku ?? '');
            $this->unit = (string) ($material->unit ?: 'pcs');
            $this->unit_cost = (string) round((float) $material->unit_cost, 2);
            $this->stock_quantity = (string) round((float) $material->stock_quantity, 3);
            $this->notes = (string) ($material->notes ?? '');
        }
    }

    public function title(): string
    {
        return $this->material ? 'Edit '.$this->material->name : 'Create Material';
    }

    public function save(ProductUnitCostService $costs): void
    {
        $this->message = null;
        $this->error = null;

        $skuUnique = $this->material
            ? 'unique:materials,sku,'.$this->material->id
            : 'unique:materials,sku';

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64', $skuUnique],
            'unit' => ['required', 'string', 'max:32'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'sku' => $validated['sku'] !== '' ? $validated['sku'] : null,
            'unit' => $validated['unit'],
            'unit_cost' => round((float) $validated['unit_cost'], 2),
            'stock_quantity' => round((float) $validated['stock_quantity'], 3),
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
        ];

        $costChanged = false;

        if ($this->material) {
            $costChanged = round((float) $this->material->unit_cost, 2) !== $payload['unit_cost'];
            $this->material->update($payload);
        } else {
            $this->material = Material::query()->create($payload);
        }

        $this->material->refresh();
        $this->unit_cost = (string) round((float) $this->material->unit_cost, 2);
        $this->stock_quantity = (string) round((float) $this->material->stock_quantity, 3);

        if ($costChanged) {
            $updated = $costs->recalculateForMaterial($this->material);
            $this->message = 'Material saved. Recalculated unit cost on '.$updated.' product(s).';
        } else {
            $this->message = 'Material saved.';
        }
    }

    public function receiveStock(ProductUnitCostService $costs): void
    {
        $this->message = null;
        $this->error = null;

        if (! $this->material) {
            $this->error = 'Save the material before receiving stock.';

            return;
        }

        $validated = $this->validate([
            'receive_quantity' => ['required', 'numeric', 'gt:0'],
            'receive_total_cost' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $result = $costs->receiveStock(
                $this->material,
                (float) $validated['receive_quantity'],
                (float) $validated['receive_total_cost'],
            );
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->material = $result['material'];
        $this->unit_cost = (string) round((float) $this->material->unit_cost, 2);
        $this->stock_quantity = (string) round((float) $this->material->stock_quantity, 3);
        $this->receive_quantity = '';
        $this->receive_total_cost = '';
        $this->message = 'Stock received. Moving-average cost updated; '.$result['products_updated'].' product(s) recalculated.';
    }

    public function delete(): void
    {
        if (! $this->material) {
            return;
        }

        if ($this->material->products()->exists()) {
            $this->error = 'Cannot delete while products still use this material.';

            return;
        }

        $this->material->delete();
        $this->redirect(route('admin.materials'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-material-edit', [
            'canDelete' => $this->material && ! $this->material->products()->exists(),
        ])->title($this->title());
    }
}
