<?php

namespace App\Services\Couriers;

use App\Models\Order;
use App\Support\CourierLocationMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RedxApiClient
{
    public function createParcel(Order $order): array
    {
        $this->assertConfigured();

        $area = $this->resolveDeliveryArea($order->city, $order->area);
        $order->loadMissing('items');

        $payload = [
            'customer_name' => mb_substr($order->name, 0, 120),
            'customer_phone' => PhoneNumber::display($order->phone),
            'delivery_area' => $area['name'],
            'delivery_area_id' => $area['id'],
            'customer_address' => $this->formatAddress($order),
            'merchant_invoice_id' => (string) $order->order_number,
            'cash_collection_amount' => (string) (int) round((float) $order->cod_amount),
            'parcel_weight' => (string) config('redx.default_weight', 1),
            'instruction' => $order->customer_note ?: null,
            'value' => (int) round((float) $order->total),
            'is_closed_box' => false,
            'parcel_details_json' => $order->items->map(fn ($item) => [
                'name' => $item->name,
                'category' => 'Product',
                'value' => (int) round((float) $item->line_total),
            ])->take(5)->values()->all(),
        ];

        $pickupStoreId = (int) config('redx.pickup_store_id');

        if ($pickupStoreId > 0) {
            $payload['pickup_store_id'] = $pickupStoreId;
        }

        return $this->request('post', (string) config('redx.endpoints.create_parcel'), $payload);
    }

    /**
     * @return array{id: int, name: string}
     */
    public function resolveDeliveryArea(?string $cityName, ?string $areaName): array
    {
        $cityName = trim((string) $cityName);
        $areaName = trim((string) $areaName);

        if ($cityName === '') {
            throw new RuntimeException('RedX dispatch requires a city on the order.');
        }

        $cacheKey = 'redx.areas.'.md5(mb_strtolower($cityName));

        $areas = Cache::remember($cacheKey, now()->addDay(), function () use ($cityName) {
            $response = $this->request('get', (string) config('redx.endpoints.areas'), [
                'district_name' => $cityName,
            ]);

            $data = data_get($response, 'areas', data_get($response, 'data', $response));

            return is_array($data) ? array_values($data) : [];
        });

        $needle = $areaName !== '' ? $areaName : $cityName;
        $match = CourierLocationMatcher::matchName($areas, $needle, ['name', 'area_name', 'delivery_area']);

        if (! $match) {
            $match = $areas[0] ?? null;
        }

        if (! is_array($match)) {
            throw new RuntimeException("RedX delivery area not found for [{$cityName} / {$areaName}].");
        }

        $id = (int) ($match['id'] ?? $match['area_id'] ?? $match['delivery_area_id'] ?? 0);
        $name = (string) ($match['name'] ?? $match['area_name'] ?? $match['delivery_area'] ?? $needle);

        if ($id <= 0) {
            throw new RuntimeException("RedX delivery area ID missing for [{$cityName} / {$areaName}].");
        }

        return ['id' => $id, 'name' => $name];
    }

    private function assertConfigured(): void
    {
        if (! app(CourierApiRegistry::class)->isConfigured('redx')) {
            throw new RuntimeException('RedX API credentials are not configured.');
        }
    }

    private function formatAddress(Order $order): string
    {
        $parts = array_filter([$order->address, $order->area, $order->city, $order->state]);

        return mb_substr(implode(', ', $parts), 0, 250);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim((string) config('redx.base_url'), '/').'/'.ltrim($path, '/');
        $token = (string) config('redx.api_token');
        $header = (string) config('redx.token_header', 'API-ACCESS-TOKEN');

        $pending = Http::timeout((int) config('redx.timeout', 30))
            ->acceptJson()
            ->withHeaders([$header => $token]);

        $response = strtolower($method) === 'get'
            ? $pending->get($url, $payload)
            : $pending->asJson()->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('RedX API error ('.$response->status().'): '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('RedX API returned an unexpected response.');
        }

        return $json;
    }
}
