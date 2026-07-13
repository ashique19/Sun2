<?php

namespace App\Services\Couriers;

use App\Models\Order;
use App\Support\CourierLocationMatcher;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PathaoApiClient
{
    public function createOrder(Order $order): array
    {
        $this->assertConfigured();

        $location = $this->resolveCityZone($order->city, $order->area);

        $payload = [
            'store_id' => (int) config('pathao.store_id'),
            'merchant_order_id' => (string) $order->order_number,
            'recipient_name' => mb_substr($order->name, 0, 100),
            'recipient_phone' => $this->phoneForPathao($order->phone),
            'recipient_address' => $this->formatAddress($order),
            'recipient_city' => $location['city_id'],
            'recipient_zone' => $location['zone_id'],
            'delivery_type' => (int) config('pathao.delivery_type', 48),
            'item_type' => (int) config('pathao.item_type', 2),
            'item_quantity' => max(1, (int) $order->items()->sum('quantity')),
            'item_weight' => (float) config('pathao.default_weight', 0.5),
            'amount_to_collect' => (int) round($order->collectableAmount()),
            'item_description' => $this->itemSummary($order),
            'special_instruction' => $order->customer_note ?: null,
        ];

        if ($location['area_id']) {
            $payload['recipient_area'] = $location['area_id'];
        }

        $response = $this->request('post', 'aladdin/api/v1/orders', array_filter(
            $payload,
            fn ($value) => $value !== null && $value !== '',
        ));

        return $response;
    }

    /**
     * @return array{city_id: int, zone_id: int, area_id: ?int}
     */
    public function resolveCityZone(?string $cityName, ?string $areaName): array
    {
        $cityName = trim((string) $cityName);
        $areaName = trim((string) $areaName);

        if ($cityName === '') {
            throw new RuntimeException('Pathao dispatch requires a city on the order.');
        }

        $cities = $this->unwrapList($this->request('get', 'aladdin/api/v1/city-list'), ['city_id', 'city_name']);
        $city = CourierLocationMatcher::matchName(
            array_map(fn (array $row) => ['id' => $row['city_id'], 'name' => $row['city_name']], $cities),
            $cityName,
        );

        if (! $city) {
            throw new RuntimeException("Pathao city not found for [{$cityName}].");
        }

        $cityId = (int) $city['id'];
        $zones = $this->unwrapList(
            $this->request('get', "aladdin/api/v1/cities/{$cityId}/zone-list"),
            ['zone_id', 'zone_name'],
        );

        $zoneMatch = CourierLocationMatcher::matchName(
            array_map(fn (array $row) => ['id' => (int) $row['zone_id'], 'name' => (string) $row['zone_name']], $zones),
            $areaName !== '' ? $areaName : $cityName,
        );

        $zoneId = (int) ($zoneMatch['id'] ?? ($zones[0]['zone_id'] ?? 0));

        if ($zoneId <= 0) {
            throw new RuntimeException("Pathao zone not found for [{$cityName} / {$areaName}].");
        }
        $areaId = null;

        if ($areaName !== '') {
            $areas = $this->unwrapList(
                $this->request('get', "aladdin/api/v1/zones/{$zoneId}/area-list"),
                ['area_id', 'area_name'],
            );

            $area = CourierLocationMatcher::matchName(
                array_map(fn (array $row) => ['id' => $row['area_id'], 'name' => $row['area_name']], $areas),
                $areaName,
            );

            if ($area) {
                $areaId = (int) ($area['id'] ?? $area['area_id'] ?? 0);
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
        if (! app(CourierApiRegistry::class)->isConfigured('pathao')) {
            throw new RuntimeException('Pathao API credentials are not configured.');
        }
    }

    private function phoneForPathao(string $phone): string
    {
        $display = PhoneNumber::display($phone);
        $digits = preg_replace('/\D+/', '', $display) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            return $digits;
        }

        throw new RuntimeException('Pathao requires a valid 11-digit Bangladesh mobile number.');
    }

    private function formatAddress(Order $order): string
    {
        $parts = array_filter([$order->address, $order->area, $order->city, $order->state]);

        return mb_substr(implode(', ', $parts), 0, 220);
    }

    private function itemSummary(Order $order): string
    {
        $order->loadMissing('items');

        return $order->items
            ->map(fn ($item) => $item->name.' x'.$item->quantity)
            ->take(3)
            ->implode('; ');
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim((string) config('pathao.base_url'), '/').'/'.ltrim($path, '/');

        $pending = Http::timeout((int) config('pathao.timeout', 30))
            ->acceptJson()
            ->withToken($this->accessToken());

        $response = strtolower($method) === 'get'
            ? $pending->get($url, $payload)
            : $pending->asJson()->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Pathao API error ('.$response->status().'): '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Pathao API returned an unexpected response.');
        }

        return $json;
    }

    private function accessToken(): string
    {
        return Cache::remember('pathao.access_token', now()->addMinutes(50), function () {
            $url = rtrim((string) config('pathao.base_url'), '/').'/aladdin/api/v1/issue-token';

            $response = Http::timeout((int) config('pathao.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'client_id' => config('pathao.client_id'),
                    'client_secret' => config('pathao.client_secret'),
                    'grant_type' => 'password',
                    'username' => config('pathao.username'),
                    'password' => config('pathao.password'),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Pathao authentication failed ('.$response->status().'): '.$response->body());
            }

            $token = data_get($response->json(), 'access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Pathao authentication did not return an access token.');
            }

            return $token;
        });
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function unwrapList(array $response, array $keys): array
    {
        $data = data_get($response, 'data.data', data_get($response, 'data', $response));

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, function ($row) use ($keys) {
            if (! is_array($row)) {
                return false;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
