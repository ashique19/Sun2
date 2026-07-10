<?php

namespace App\Services\Couriers;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SteadfastApiClient
{
    public function createOrder(array $payload): array
    {
        $response = $this->request('post', '/create_order', $payload);

        return $response;
    }

    public function getStatusByInvoice(string $invoice): array
    {
        return $this->request('get', '/status_by_invoice/'.urlencode($invoice));
    }

    public function getStatusByTrackingCode(string $trackingCode): array
    {
        return $this->request('get', '/status_by_trackingcode/'.urlencode($trackingCode));
    }

    public function getStatusByConsignmentId(string|int $consignmentId): array
    {
        return $this->request('get', '/status_by_cid/'.urlencode((string) $consignmentId));
    }

    /**
     * Current merchant wallet balance at Steadfast/Packzy.
     */
    public function getBalance(): float
    {
        $response = $this->request('get', '/get_balance');

        $balance = $response['current_balance']
            ?? $response['balance']
            ?? data_get($response, 'data.current_balance')
            ?? data_get($response, 'data.balance');

        if ($balance === null || ! is_numeric($balance)) {
            throw new RuntimeException('Steadfast did not return a balance.');
        }

        return round((float) $balance, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function fraudCheck(string $phone): array
    {
        $display = PhoneNumber::extractFirstBangladeshMobile($phone) ?? PhoneNumber::display($phone);
        $digits = preg_replace('/\D+/', '', $display) ?? '';

        if ($digits === '') {
            throw new RuntimeException('A valid phone number is required for fraud check.');
        }

        $response = $this->request('get', '/fraud_check/'.$digits);

        $totalParcels = (int) ($response['total_parcels'] ?? 0);
        $totalDelivered = (int) ($response['total_delivered'] ?? 0);

        $response['success_ratio'] = $totalParcels > 0
            ? (int) round(($totalDelivered / $totalParcels) * 100)
            : (int) ($response['success_ratio'] ?? 0);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $apiKey = config('steadfast.api_key');
        $secretKey = config('steadfast.secret_key');

        if (! $apiKey || ! $secretKey) {
            throw new RuntimeException('Steadfast API credentials are not configured.');
        }

        $url = config('steadfast.base_url').$path;

        $pending = Http::timeout(config('steadfast.timeout', 30))
            ->withHeaders([
                'Api-Key' => $apiKey,
                'Secret-Key' => $secretKey,
                'Accept' => 'application/json',
            ]);

        $response = match (strtolower($method)) {
            'get' => $pending->get($url),
            default => $pending->asJson()->post($url, $payload),
        };

        if (! $response->successful()) {
            throw new RuntimeException('Steadfast API error ('.$response->status().'): '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Steadfast API returned an unexpected response.');
        }

        return $json;
    }
}
