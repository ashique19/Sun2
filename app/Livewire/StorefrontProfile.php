<?php

namespace App\Livewire;

use App\Models\Area;
use App\Models\City;
use App\Rules\BangladeshMobile;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontProfile extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public ?int $cityId = null;

    public ?int $areaId = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->phone = $user->phone;
        $this->email = (string) ($user->email ?? '');

        $defaultAddress = $user->addresses()->where('is_default', true)->first()
            ?? $user->addresses()->latest()->first();

        if ($defaultAddress) {
            $this->address = (string) $defaultAddress->address;
            $this->cityId = $defaultAddress->city_id;
            $this->areaId = $defaultAddress->area_id;

            if (! $this->cityId && $defaultAddress->city) {
                $this->cityId = City::query()
                    ->active()
                    ->where('name', $defaultAddress->city)
                    ->value('id');
            }

            if (! $this->areaId && $defaultAddress->area && $this->cityId) {
                $this->areaId = Area::query()
                    ->active()
                    ->where('city_id', $this->cityId)
                    ->where('name', $defaultAddress->area)
                    ->value('id');
            }
        }
    }

    public function updatedCityId(): void
    {
        $this->areaId = null;
    }

    public function save(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new BangladeshMobile, 'unique:users,phone,'.$user->id],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:500'],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            'areaId' => ['nullable', 'integer', 'exists:areas,id'],
        ]);

        if (! empty($validated['cityId']) && ! empty($validated['areaId'])) {
            $areaBelongsToCity = Area::query()
                ->whereKey($validated['areaId'])
                ->where('city_id', $validated['cityId'])
                ->exists();

            if (! $areaBelongsToCity) {
                $this->addError('areaId', 'Selected area does not belong to the chosen city.');

                return;
            }
        }

        $user->update([
            'name' => $validated['name'],
            'phone' => PhoneNumber::display($validated['phone']),
            'email' => $validated['email'] ?: null,
        ]);

        $city = ! empty($validated['cityId']) ? City::query()->find($validated['cityId']) : null;
        $area = ! empty($validated['areaId']) ? Area::query()->find($validated['areaId']) : null;
        $hasAddress = trim((string) ($validated['address'] ?? '')) !== ''
            || $city
            || $area;

        if ($hasAddress) {
            $defaultAddress = $user->addresses()->where('is_default', true)->first()
                ?? $user->addresses()->make(['is_default' => true]);

            $defaultAddress->fill([
                'name' => $validated['name'],
                'phone' => PhoneNumber::display($validated['phone']),
                'address' => trim((string) ($validated['address'] ?? '')),
                'city_id' => $city?->id,
                'area_id' => $area?->id,
                'city' => $city?->name,
                'area' => $area?->name,
                'state' => $city?->name,
                'is_default' => true,
            ])->save();

            $user->addresses()
                ->where('id', '!=', $defaultAddress->id)
                ->update(['is_default' => false]);
        }

        $this->statusMessage = 'Profile updated successfully.';
    }

    public function render()
    {
        $cities = City::query()->active()->orderBy('name')->get(['id', 'name']);

        $areas = $this->cityId
            ? Area::query()->active()->where('city_id', $this->cityId)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('livewire.storefront-profile', [
            'cities' => $cities,
            'areas' => $areas,
        ]);
    }
}
