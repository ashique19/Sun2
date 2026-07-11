<?php

namespace App\Livewire\Admin;

use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Cities & Areas')]
#[Layout('components.layouts.admin')]
class AdminCities extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?string $error = null;

    public ?string $message = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function delete(int $cityId): void
    {
        $this->error = null;
        $this->message = null;

        $city = City::query()->withCount('areas')->findOrFail($cityId);

        $city->delete();
        AddressLocationGuesser::clearCache();

        $this->message = 'City “'.$city->name.'” deleted'
            .($city->areas_count > 0 ? ' (and '.$city->areas_count.' areas).' : '.');
    }

    public function render()
    {
        $cities = City::query()
            ->withCount('areas')
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('division', 'like', $term)
                        ->orWhere('slug', 'like', $term);
                });
            })
            ->when($this->status === '1', fn ($q) => $q->where('is_active', true))
            ->when($this->status === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.admin-cities', [
            'cities' => $cities,
        ]);
    }
}
