<?php

namespace App\Livewire\Admin;

use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminCityEdit extends Component
{
    public ?City $city = null;

    public string $name = '';

    public string $slug = '';

    public string $division = '';

    public bool $is_dhaka = false;

    public bool $is_active = true;

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?City $city = null): void
    {
        if ($city?->exists) {
            $this->city = $city;
            $this->name = $city->name;
            $this->slug = (string) ($city->slug ?? '');
            $this->division = (string) ($city->division ?? '');
            $this->is_dhaka = (bool) $city->is_dhaka;
            $this->is_active = (bool) $city->is_active;
        }
    }

    public function title(): string
    {
        return $this->city ? 'Edit '.$this->city->name : 'Create City';
    }

    public function updatedName(string $value): void
    {
        if ($this->city) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function save(): void
    {
        $this->message = null;
        $this->error = null;

        $isCreate = $this->city === null;

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('cities', 'name')->ignore($this->city?->id),
            ],
            'slug' => [
                'required',
                'string',
                'max:120',
                Rule::unique('cities', 'slug')->ignore($this->city?->id),
            ],
            'division' => ['nullable', 'string', 'max:120'],
            'is_dhaka' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = Str::slug($validated['slug']) ?: Str::slug($validated['name']);
        $validated['division'] = $validated['division'] !== '' ? $validated['division'] : null;

        if ($this->city) {
            $this->city->update($validated);
        } else {
            $this->city = City::query()->create($validated);
        }

        AddressLocationGuesser::clearCache();
        $this->city = $this->city->fresh();

        if ($isCreate) {
            $this->redirect(route('admin.cities.edit', $this->city), navigate: true);

            return;
        }

        $this->slug = (string) ($this->city->slug ?? '');
        $this->division = (string) ($this->city->division ?? '');
        $this->is_dhaka = (bool) $this->city->is_dhaka;
        $this->is_active = (bool) $this->city->is_active;
        $this->message = 'City saved.';
    }

    public function delete(): void
    {
        $this->error = null;
        $this->message = null;

        if (! $this->city) {
            return;
        }

        $this->city->loadCount('areas');
        $areasCount = $this->city->areas_count;
        $name = $this->city->name;

        $this->city->delete();
        AddressLocationGuesser::clearCache();

        session()->flash('status', 'City “'.$name.'” deleted'
            .($areasCount > 0 ? ' (and '.$areasCount.' areas).' : '.'));

        $this->redirect(route('admin.cities'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-city-edit', [
            'areasCount' => $this->city?->areas()->count() ?? 0,
        ])->title($this->title());
    }
}
