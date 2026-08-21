<?php

namespace App\Services\Couriers;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Merchant-panel success lookup (portal JSON login + /user/success).
 * Gated by SCRAP_PATHAO — not part of the official courier dispatch API.
 */
class PathaoMerchantSuccessClient
{
    /**
     * @return array{
     *     data_type: string,
     *     success_ratio: int|null,
     *     total_delivered: int|null,
     *     total_parcels: int|null,
     *     total_cancelled: int|null,
     *     customer_rating: string|null,
     *     risk_level: string|null,
     *     label: string
     * }
     */
    public function successCheck(string $phone): array
    {
        $this->assertScrapConfigured();

        $display = PhoneNumber::extractFirstBangladeshMobile($phone) ?? PhoneNumber::display($phone);

        if (! PhoneNumber::isValidDisplayMobile($display)) {
            throw new RuntimeException('A valid phone number is required for Pathao success check.');
        }

        $response = $this->postSuccess($display, $this->accessToken());

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $response = $this->postSuccess($display, $this->accessToken(forceRefresh: true));
        }

        if (! $response->successful()) {
            throw new RuntimeException('Pathao success check failed ('.$response->status().').');
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Pathao success check returned an unexpected response.');
        }

        return $this->normalize($json);
    }

    public function isScrapConfigured(): bool
    {
        return (bool) config('pathao.scrap')
            && filled(config('pathao.username'))
            && filled(config('pathao.password'));
    }

    private function assertScrapConfigured(): void
    {
        if (! $this->isScrapConfigured()) {
            throw new RuntimeException('Pathao success scraping is not configured.');
        }
    }

    private function postSuccess(string $phone, string $token): Response
    {
        return Http::timeout((int) config('pathao.scrap_timeout', 20))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->post($this->portalUrl('/api/v1/user/success'), [
                'phone' => $phone,
            ]);
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        if ($forceRefresh) {
            Cache::forget($this->tokenCacheKey());
        }

        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(50), function () {
            $response = Http::timeout((int) config('pathao.scrap_timeout', 20))
                ->acceptJson()
                ->asJson()
                ->post($this->portalUrl('/api/v1/login'), [
                    'username' => config('pathao.username'),
                    'password' => config('pathao.password'),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Pathao merchant login failed ('.$response->status().').');
            }

            $token = data_get($response->json(), 'access_token')
                ?? data_get($response->json(), 'data.access_token')
                ?? data_get($response->json(), 'token');

            if (! is_string($token) || trim($token) === '') {
                throw new RuntimeException('Pathao merchant login did not return an access token.');
            }

            return trim($token);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     data_type: string,
     *     success_ratio: int|null,
     *     total_delivered: int|null,
     *     total_parcels: int|null,
     *     total_cancelled: int|null,
     *     customer_rating: string|null,
     *     risk_level: string|null,
     *     label: string
     * }
     */
    private function normalize(array $payload): array
    {
        $customer = data_get($payload, 'data.customer', data_get($payload, 'customer', $payload));
        if (! is_array($customer)) {
            $customer = [];
        }

        $rating = $this->stringOrNull(
            $customer['customer_rating']
                ?? $customer['rating']
                ?? data_get($payload, 'data.customer_rating')
                ?? data_get($payload, 'data.rating')
        );
        $risk = $this->stringOrNull(
            $customer['risk_level']
                ?? data_get($payload, 'data.risk_level')
        );

        $delivered = $this->intOrNull(
            $customer['successful_delivery']
                ?? $customer['success']
                ?? $customer['delivered']
                ?? null
        );
        $total = $this->intOrNull(
            $customer['total_delivery']
                ?? $customer['total']
                ?? $customer['total_parcels']
                ?? null
        );
        $cancelled = $this->intOrNull(
            $customer['cancelled_delivery']
                ?? $customer['cancel']
                ?? $customer['cancelled']
                ?? null
        );

        if ($total !== null && $delivered !== null && $cancelled === null) {
            $cancelled = max(0, $total - $delivered);
        }

        $ratio = null;
        if ($total !== null && $total > 0 && $delivered !== null) {
            $ratio = (int) round(($delivered / $total) * 100);
        } else {
            $rawRatio = $customer['success_ratio']
                ?? $customer['success_rate']
                ?? data_get($payload, 'data.success_rate')
                ?? data_get($payload, 'data.success_ratio');
            if (is_numeric($rawRatio)) {
                $ratio = (int) round((float) $rawRatio);
            }
        }

        if ($rating === null && $ratio === null && $total === null) {
            throw new RuntimeException('Pathao success check returned no rating or delivery counts.');
        }

        $dataType = ($delivered !== null || $total !== null) ? 'counts' : 'rating';
        if ($rating !== null && ($total === null || $total === 0) && $delivered === null) {
            $dataType = 'rating';
        }

        return [
            'data_type' => $dataType,
            'success_ratio' => $ratio,
            'total_delivered' => $delivered,
            'total_parcels' => $total,
            'total_cancelled' => $cancelled,
            'customer_rating' => $rating,
            'risk_level' => $risk,
            'label' => $this->label($rating, $ratio, $risk),
        ];
    }

    private function label(?string $rating, ?int $ratio, ?string $risk): string
    {
        if ($rating !== null) {
            $nice = $this->humanize($rating);

            return $nice.($risk ? ' ('.$this->humanize($risk).' risk)' : '');
        }

        if ($ratio !== null) {
            return $ratio.'% success';
        }

        return 'No Pathao history';
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', strtolower(trim($value))));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function portalUrl(string $path): string
    {
        return rtrim((string) config('pathao.merchant_base_url'), '/').'/'.ltrim($path, '/');
    }

    private function tokenCacheKey(): string
    {
        return 'pathao.merchant_portal.access_token';
    }
}
