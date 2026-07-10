<?php

namespace App\Services\Locations;

use App\Models\Area;
use App\Models\City;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BangladeshLocationImporter
{
    /**
     * @return array{cities: int, areas: int}
     */
    public function import(?OutputStyle $output = null): array
    {
        $basePath = database_path('data/bangladesh');

        $divisions = $this->readJson($basePath.'/divisions.json');
        $districts = $this->readJson($basePath.'/districts.json');
        $units = $this->readJson($basePath.'/units.json');

        $divisionNames = collect($divisions)->mapWithKeys(
            fn (array $division) => [$division['id'] => $division['en']],
        );

        return DB::transaction(function () use ($districts, $units, $divisionNames, $output) {
            $cityMap = [];

            foreach ($districts as $district) {
                $divisionName = $divisionNames->get($district['divisionId']);
                $isDhaka = $district['id'] === 'dhaka-dhaka';

                $city = City::query()->updateOrCreate(
                    ['slug' => $district['id']],
                    [
                        'name' => $district['en'],
                        'division' => $divisionName,
                        'is_dhaka' => $isDhaka,
                        'is_active' => true,
                    ],
                );

                $cityMap[$district['id']] = $city->id;
            }

            $areaCount = 0;

            foreach ($units as $unit) {
                $cityId = $cityMap[$unit['districtId']] ?? null;

                if (! $cityId) {
                    continue;
                }

                Area::query()->updateOrCreate(
                    ['slug' => $unit['id']],
                    [
                        'city_id' => $cityId,
                        'name' => $unit['en'],
                        'police_station' => $unit['en'],
                        'unit_type' => $unit['type'] ?? null,
                        'delivery_charge_upto_5' => $this->deliveryChargeUpto5($unit),
                        'delivery_charge_over_5' => $this->deliveryChargeOver5($unit),
                        'is_active' => true,
                    ],
                );

                $areaCount++;
            }

            $cityCount = City::query()->count();

            $output?->info(sprintf(
                'Imported %d districts and %d police stations / upazilas.',
                $cityCount,
                $areaCount,
            ));

            return [
                'cities' => $cityCount,
                'areas' => $areaCount,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Location dataset not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        return $data;
    }

    private function deliveryChargeUpto5(array $unit): int
    {
        if ($unit['districtId'] === 'dhaka-dhaka' && ($unit['type'] ?? null) === 'thana') {
            return (int) config('checkout.dhaka_city_delivery_upto_5', 80);
        }

        return (int) config('checkout.outside_delivery_upto_5', 120);
    }

    private function deliveryChargeOver5(array $unit): int
    {
        if ($unit['districtId'] === 'dhaka-dhaka' && ($unit['type'] ?? null) === 'thana') {
            return (int) config('checkout.dhaka_city_delivery_over_5', 150);
        }

        return (int) config('checkout.outside_delivery_over_5', 200);
    }
}
