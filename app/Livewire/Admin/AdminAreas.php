<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Areas')]
#[Layout('components.layouts.admin')]
class AdminAreas extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $city = '';

    #[Url]
    public string $status = '';

    public ?string $error = null;

    public ?string $message = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function delete(int $areaId): void
    {
        $this->error = null;
        $this->message = null;

        $area = Area::query()->findOrFail($areaId);
        $name = $area->name;
        $area->delete();
        AddressLocationGuesser::clearCache();

        $this->message = 'Area “'.$name.'” deleted.';
    }

    public function render()
    {
        $areas = Area::query()
            ->with('city:id,name')
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('police_station', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('unit_type', 'like', $term)
                        ->orWhere('aliases', 'like', $term);
                });
            })
            ->when($this->city !== '', fn ($q) => $q->where('city_id', $this->city))
            ->when($this->status === '1', fn ($q) => $q->where('is_active', true))
            ->when($this->status === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.admin-areas', [
            'areas' => $areas,
            'cities' => City::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
