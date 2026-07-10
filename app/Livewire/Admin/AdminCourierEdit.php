<?php

namespace App\Livewire\Admin;

use App\Models\Courier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminCourierEdit extends Component
{
    public ?Courier $courier = null;

    public string $name = '';

    public string $slug = '';

    public string $charge = '60';

    public string $osd_charge = '110';

    public string $customer_charge = '80';

    public string $customer_osd_charge = '120';

    public string $cod_percentage = '1';

    public string $balance = '0';

    public bool $is_default = false;

    public bool $is_active = true;

    public ?string $message = null;

    public function mount(?Courier $courier = null): void
    {
        if ($courier?->exists) {
            $this->courier = $courier;
            $this->name = $courier->name;
            $this->slug = (string) ($courier->slug ?? '');
            $this->charge = (string) (int) round((float) $courier->charge);
            $this->osd_charge = (string) (int) round((float) $courier->osd_charge);
            $this->customer_charge = (string) (int) round((float) $courier->customer_charge);
            $this->customer_osd_charge = (string) (int) round((float) $courier->customer_osd_charge);
            $this->cod_percentage = rtrim(rtrim(number_format((float) $courier->cod_percentage, 2, '.', ''), '0'), '.') ?: '0';
            $this->balance = (string) (int) round((float) $courier->balance);
            $this->is_default = (bool) $courier->is_default;
            $this->is_active = (bool) $courier->is_active;

            return;
        }

        // New courier: prefer Steadfast defaults when that slug is still free.
        if (! Courier::query()->where('slug', 'steadfast')->exists()) {
            $this->name = 'Steadfast';
            $this->slug = 'steadfast';
            $this->is_default = ! Courier::query()->where('is_default', true)->exists();
        }
    }

    public function title(): string
    {
        return $this->courier ? 'Edit '.$this->courier->name : 'Create Courier';
    }

    public function updatedName(string $value): void
    {
        if ($this->courier) {
            return;
        }

        $slug = Str::slug($value);
        $this->slug = $slug;

        if ($slug === 'steadfast' && ! Courier::query()->where('is_default', true)->exists()) {
            $this->is_default = true;
        }
    }

    public function save(): void
    {
        $this->message = null;

        $isCreate = $this->courier === null;

        $slugUnique = Rule::unique('couriers', 'slug')->whereNotNull('slug');
        if ($this->courier) {
            $slugUnique = $slugUnique->ignore($this->courier->id);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:64', $slugUnique],
            'charge' => ['required', 'numeric', 'min:0'],
            'osd_charge' => ['required', 'numeric', 'min:0'],
            'customer_charge' => ['required', 'numeric', 'min:0'],
            'customer_osd_charge' => ['required', 'numeric', 'min:0'],
            'cod_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'balance' => ['required', 'numeric'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $validated['slug'] = $validated['slug'] !== '' ? Str::slug($validated['slug']) : null;
        $validated['charge'] = (int) round((float) $validated['charge']);
        $validated['osd_charge'] = (int) round((float) $validated['osd_charge']);
        $validated['customer_charge'] = (int) round((float) $validated['customer_charge']);
        $validated['customer_osd_charge'] = (int) round((float) $validated['customer_osd_charge']);
        $validated['cod_percentage'] = round((float) $validated['cod_percentage'], 2);
        $validated['balance'] = (int) round((float) $validated['balance']);

        if ($validated['is_default'] && ! $validated['is_active']) {
            $this->addError('is_active', 'The default courier must be active.');

            return;
        }

        DB::transaction(function () use ($validated) {
            if ($validated['is_default']) {
                Courier::query()->where('is_default', true)->update(['is_default' => false]);
            }

            if ($this->courier) {
                $this->courier->update($validated);
            } else {
                $this->courier = Courier::query()->create($validated);
            }
        });

        $this->courier = $this->courier->fresh();

        if ($isCreate) {
            $this->redirect(route('admin.couriers.edit', $this->courier), navigate: true);

            return;
        }

        $this->is_default = (bool) $this->courier->is_default;
        $this->is_active = (bool) $this->courier->is_active;
        $this->balance = (string) (int) round((float) $this->courier->balance);
        $this->message = 'Courier saved.';
    }

    public function delete(): void
    {
        if (! $this->courier) {
            return;
        }

        $this->courier->loadCount('orders');

        if ($this->courier->orders_count > 0) {
            $this->addError('name', 'Cannot delete a courier that still has orders. Deactivate it instead.');

            return;
        }

        if ($this->courier->is_default) {
            $this->addError('is_default', 'Cannot delete the default courier. Set another as default first.');

            return;
        }

        $this->courier->delete();
        $this->redirect(route('admin.couriers'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-courier-edit', [
            'apiSlugs' => config('couriers.api_slugs', []),
            'canDelete' => $this->courier
                && ! $this->courier->is_default
                && $this->courier->orders()->doesntExist(),
        ])->title($this->title());
    }
}
