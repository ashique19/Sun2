<?php

namespace App\Services\Couriers;

use App\Models\Order;
use App\Support\CourierLocationMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CarryBeeApiClient
{
    public function createOrder(Order $order): array
    {
        $this->assertConfigured();

        $location = $this->resolveCityZone($order);
        $order->loadMissing('items');

        $payload = [
            'store_id' => (string) config('carrybee.store_id'),
            'merchant_order_id' => (string) $order->order_number,
            'delivery_type' => (int) config('carrybee.delivery_type', 1),
            'product_type' => (int) config('carrybee.product_type', 1),
            'recipient_phone' => $this->phoneDigits($order->phone),
            'recipient_name' => mb_substr($order->name, 0, 100),
            'recipient_address' => $this->formatAddress($order),
            'city_id' => $location['city_id'],
            'zone_id' => $location['zone_id'],
            'item_weight' => $this->orderWeightGrams($order),
            'item_quantity' => max(1, (int) $order->items->sum('quantity')),
            'collectable_amount' => (int) round($order->collectableAmount()),
            'product_description' => $this->itemSummary($order),
        ];

        if ($location['area_id']) {
            $payload['area_id'] = $location['area_id'];
        }

        return $this->request('post', '/api/v2/orders', $payload);
    }

    /**
     * @return array{city_id: int, zone_id: int, area_id: ?int}
     */
    public function resolveCityZone(Order $order): array
    {
        $address = $this->formatAddress($order);

        try {
            $details = $this->request('post', '/api/v2/address-details', ['query' => $address]);
            $data = data_get($details, 'data', $details);

            $cityId = (int) data_get($data, 'city_id', 0);
            $zoneId = (int) data_get($data, 'zone_id', 0);
            $areaId = (int) data_get($data, 'area_id', 0);

            if ($cityId > 0 && $zoneId > 0) {
                return [
                    'city_id' => $cityId,
                    'zone_id' => $zoneId,
                    'area_id' => $areaId > 0 ? $areaId : null,
                ];
            }
        } catch (\Throwable) {
            // Fall back to city/zone lists below.
        }

        $cityName = trim((string) $order->city);
        $areaName = trim((string) $order->area);

        if ($cityName === '') {
            throw new RuntimeException('CarryBee dispatch requires a city on the order.');
        }

        $cities = data_get($this->request('get', '/api/v2/cities'), 'data.cities',
            data_get($this->request('get', '/api/v2/cities'), 'cities', []),
        );

        if (! is_array($cities)) {
            $cities = [];
        }

        $city = CourierLocationMatcher::matchName($cities, $cityName);

        if (! $city) {
            throw new RuntimeException("CarryBee city not found for [{$cityName}].");
        }

        $cityId = (int) ($city['id'] ?? 0);
        $zonesResponse = $this->request('get', '/api/v2/cities/'.$cityId.'/zones');
        $zones = data_get($zonesResponse, 'data.zones', data_get($zonesResponse, 'zones', []));

        if (! is_array($zones)) {
            $zones = [];
        }

        $zone = CourierLocationMatcher::matchName($zones, $areaName !== '' ? $areaName : $cityName) ?? ($zones[0] ?? null);

        if (! is_array($zone)) {
            throw new RuntimeException("CarryBee zone not found for [{$cityName} / {$areaName}].");
        }

        $zoneId = (int) ($zone['id'] ?? 0);
        $areaId = null;

        if ($areaName !== '') {
            $areasResponse = $this->request('get', '/api/v2/cities/'.$cityId.'/zones/'.$zoneId.'/areas');
            $areas = data_get($areasResponse, 'data.areas', data_get($areasResponse, 'areas', []));

            if (is_array($areas)) {
                $area = CourierLocationMatcher::matchName($areas, $areaName);
                $areaId = $area ? (int) ($area['id'] ?? 0) : null;
            }
        }

        return [
            'city_id' => $cityId,
            'zone_id' => $zoneId,
            'area_id' => $areaId ?: null,
        ];
    }

    private function assertConfigured(): void
    {
        if (! app(CourierApiRegistry::class)->isConfigured('carrybee')) {
            throw new RuntimeException('CarryBee API credentials are not configured.');
        }
    }

    private function phoneDigits(string $phone): string
    {
        $display = PhoneNumber::display($phone);
        $digits = preg_replace('/\D+/', '', $display) ?? '';

        if ($digits === '') {
            throw new RuntimeException('CarryBee requires a valid customer phone number.');
        }

        return $digits;
    }

    private function formatAddress(Order $order): string
    {
        $parts = array_filter([$order->address, $order->area, $order->city, $order->state]);

        return mb_substr(implode(', ', $parts), 0, 220);
    }

    private function itemSummary(Order $order): string
    {
        return $order->items
            ->map(fn ($item) => $item->name.' x'.$item->quantity)
            ->take(3)
            ->implode(', ');
    }

    private function orderWeightGrams(Order $order): int
    {
        $quantity = max(1, (int) $order->items->sum('quantity'));

        return min((int) config('carrybee.default_weight_grams', 500) * $quantity, 25000);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim((string) config('carrybee.base_url'), '/').'/'.ltrim($path, '/');

        $pending = Http::timeout((int) config('carrybee.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Client-ID' => (string) config('carrybee.client_id'),
                'Client-Secret' => (string) config('carrybee.client_secret'),
                'Client-Context' => (string) config('carrybee.client_context'),
            ]);

        $response = match (strtolower($method)) {
            'get' => $pending->get($url, $payload),
            default => $pending->asJson()->post($url, $payload),
        };

        if (! $response->successful()) {
            throw new RuntimeException('CarryBee API error ('.$response->status().'): '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('CarryBee API returned an unexpected response.');
        }

        if (
            $response->status() >= 400
            || ($json['status'] ?? null) === false
            || ! empty($json['error'])
            || ! empty($json['errors'])
        ) {
            $message = (string) ($json['message'] ?? $json['error'] ?? 'CarryBee API error');

            throw new RuntimeException($message);
        }

        return $json;
    }
}
