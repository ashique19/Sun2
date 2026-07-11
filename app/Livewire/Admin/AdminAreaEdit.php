<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminAreaEdit extends Component
{
    public ?Area $area = null;

    #[Url]
    public ?int $city = null;

    public string $name = '';

    public string $slug = '';

    public ?int $cityId = null;

    public string $police_station = '';

    public string $unit_type = '';

    public string $delivery_charge_upto_5 = '120';

    public string $delivery_charge_over_5 = '200';

    public bool $is_active = true;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?Area $area = null): void
    {
        if ($area?->exists) {
            $this->area = $area;
            $this->name = $area->name;
            $this->slug = (string) ($area->slug ?? '');
            $this->cityId = $area->city_id;
            $this->police_station = (string) ($area->police_station ?? '');
            $this->unit_type = (string) ($area->unit_type ?? '');
            $this->delivery_charge_upto_5 = (string) (int) $area->delivery_charge_upto_5;
            $this->delivery_charge_over_5 = (string) (int) $area->delivery_charge_over_5;
            $this->is_active = (bool) $area->is_active;

            return;
        }

        if ($this->city) {
            $this->cityId = $this->city;
        }
    }

    public function title(): string
    {
        return $this->area ? 'Edit '.$this->area->name : 'Create Area';
    }

    public function updatedName(string $value): void
    {
        if ($this->area) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function save(): void
    {
        $this->message = null;
        $this->error = null;

        $isCreate = $this->area === null;

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('areas', 'name')
                    ->where(fn ($query) => $query->where('city_id', $this->cityId))
                    ->ignore($this->area?->id),
            ],
            'slug' => [
                'required',
                'string',
                'max:160',
                Rule::unique('areas', 'slug')->ignore($this->area?->id),
            ],
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'police_station' => ['nullable', 'string', 'max:120'],
            'unit_type' => ['nullable', 'string', 'max:32'],
            'delivery_charge_upto_5' => ['required', 'integer', 'min:0', 'max:65535'],
            'delivery_charge_over_5' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'slug' => Str::slug($validated['slug']) ?: Str::slug($validated['name']),
            'city_id' => (int) $validated['cityId'],
            'police_station' => $validated['police_station'] !== '' ? $validated['police_station'] : null,
            'unit_type' => $validated['unit_type'] !== '' ? $validated['unit_type'] : null,
            'delivery_charge_upto_5' => (int) $validated['delivery_charge_upto_5'],
            'delivery_charge_over_5' => (int) $validated['delivery_charge_over_5'],
            'is_active' => $validated['is_active'],
        ];

        if ($this->area) {
            $this->area->update($payload);
        } else {
            $this->area = Area::query()->create($payload);
        }

        AddressLocationGuesser::clearCache();
        $this->area = $this->area->fresh();

        if ($isCreate) {
            $this->redirect(route('admin.areas.edit', $this->area), navigate: true);

            return;
        }

        $this->slug = (string) ($this->area->slug ?? '');
        $this->cityId = $this->area->city_id;
        $this->police_station = (string) ($this->area->police_station ?? '');
        $this->unit_type = (string) ($this->area->unit_type ?? '');
        $this->delivery_charge_upto_5 = (string) (int) $this->area->delivery_charge_upto_5;
        $this->delivery_charge_over_5 = (string) (int) $this->area->delivery_charge_over_5;
        $this->is_active = (bool) $this->area->is_active;
        $this->message = 'Area saved.';
    }

    public function delete(): void
    {
        $this->error = null;
        $this->message = null;

        if (! $this->area) {
            return;
        }

        $cityId = $this->area->city_id;
        $name = $this->area->name;
        $this->area->delete();
        AddressLocationGuesser::clearCache();

        session()->flash('status', 'Area “'.$name.'” deleted.');

        $this->redirect(route('admin.areas', ['city' => $cityId]), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-area-edit', [
            'cities' => City::query()->orderBy('name')->get(['id', 'name']),
        ])->title($this->title());
    }
}
