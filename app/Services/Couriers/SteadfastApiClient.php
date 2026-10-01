<?php

namespace App\Services\Couriers;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
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
     *
     * Uses a short timeout so admin list pages stay responsive when the API is slow.
     */
    public function getBalance(): float
    {
        $response = $this->request('get', '/get_balance', timeout: 5);

        $balance = $response['current_balance']
            ?? $response['balance']
            ?? data_get($response, 'data.current_balance')
            ?? data_get($response, 'data.balance');

        if ($balance === null || ! is_numeric($balance)) {
            throw new RuntimeException('Steadfast did not return a balance.');
        }

        return round((float) $balance, 2);
    }

    public function isFraudCheckAvailable(): bool
    {
        return $this->hasApiCredentials() || $this->hasFraudPanelCredentials();
    }

    /**
     * Customer delivery stats used by admin order forms.
     *
     * Tries the Packzy API first (`/fraud_check/{phone}`), then falls back to the
     * merchant panel (`/user/frauds/check/{phone}`) when panel credentials are set.
     * Steadfast's documented API payload uses `Total_parcels` (capital T); we
     * normalize keys so the UI always receives lowercase totals + success_ratio.
     *
     * @return array{
     *     total_parcels: int,
     *     total_delivered: int,
     *     total_cancelled: int,
     *     total_fraud_reports: mixed,
     *     success_ratio: int
     * }
     */
    public function fraudCheck(string $phone): array
    {
        $digits = $this->fraudCheckPhoneDigits($phone);

        $apiError = null;

        if ($this->hasApiCredentials()) {
            try {
                return $this->normalizeFraudResponse(
                    $this->request('get', '/fraud_check/'.$digits)
                );
            } catch (\Throwable $e) {
                $apiError = $e;
            }
        }

        if ($this->hasFraudPanelCredentials()) {
            return $this->fraudCheckViaPanel($digits);
        }

        if ($apiError !== null) {
            throw $apiError;
        }

        throw new RuntimeException('Steadfast fraud check is not configured.');
    }

    /**
     * @return array{
     *     total_parcels: int,
     *     total_delivered: int,
     *     total_cancelled: int,
     *     total_fraud_reports: mixed,
     *     success_ratio: int
     * }
     */
    private function fraudCheckViaPanel(string $phoneDigits): array
    {
        $panelUrl = (string) config('steadfast.fraud.panel_url');
        $email = (string) config('steadfast.fraud.email');
        $password = (string) config('steadfast.fraud.password');
        $domain = parse_url($panelUrl, PHP_URL_HOST) ?: 'steadfast.com.bd';

        $loginPage = $this->panelClient($panelUrl)->get('/login');

        if (! $loginPage->successful()) {
            throw new RuntimeException('Steadfast fraud panel login page is unavailable.');
        }

        $csrfToken = $this->extractCsrfToken($loginPage->body());

        if ($csrfToken === null) {
            throw new RuntimeException('Steadfast fraud panel CSRF token was not found.');
        }

        $cookies = $this->cookieMap($loginPage->cookies());

        $loginResponse = $this->panelClient($panelUrl)
            ->withCookies($cookies, $domain)
            ->asForm()
            ->post('/login', [
                '_token' => $csrfToken,
                'email' => $email,
                'password' => $password,
            ]);

        if (! ($loginResponse->successful() || $loginResponse->redirect())) {
            throw new RuntimeException('Steadfast fraud panel login failed. Check STEADFAST_FRAUD_EMAIL / STEADFAST_FRAUD_PASSWORD.');
        }

        $sessionCookies = $this->cookieMap($loginResponse->cookies());

        if ($sessionCookies === []) {
            $sessionCookies = $cookies;
        }

        try {
            $fraudResponse = $this->panelClient($panelUrl)
                ->withCookies($sessionCookies, $domain)
                ->get('/user/frauds/check/'.$phoneDigits);

            if (! $fraudResponse->successful()) {
                throw new RuntimeException(
                    'Steadfast fraud panel error ('.$fraudResponse->status().'): '.$fraudResponse->body()
                );
            }

            $json = $fraudResponse->json();

            if (! is_array($json)) {
                throw new RuntimeException('Steadfast fraud panel returned an unexpected response.');
            }

            return $this->normalizeFraudResponse($json);
        } finally {
            $this->logoutFromPanel($panelUrl, $domain, $sessionCookies);
        }
    }

    /**
     * @param  array<string, string>  $cookies
     */
    private function logoutFromPanel(string $panelUrl, string $domain, array $cookies): void
    {
        try {
            $page = $this->panelClient($panelUrl)
                ->withCookies($cookies, $domain)
                ->get('/user/frauds/check');

            if (! $page->successful()) {
                return;
            }

            $csrfToken = $this->extractCsrfToken($page->body());

            if ($csrfToken === null) {
                return;
            }

            $this->panelClient($panelUrl)
                ->withCookies($cookies, $domain)
                ->asForm()
                ->post('/logout', [
                    '_token' => $csrfToken,
                ]);
        } catch (\Throwable) {
            // Best-effort logout; fraud data already retrieved.
        }
    }

    private function panelClient(string $panelUrl): PendingRequest
    {
        return Http::baseUrl($panelUrl)
            ->timeout((int) config('steadfast.timeout', 30))
            ->withHeaders([
                'Accept' => 'application/json, text/html, */*',
                'User-Agent' => 'Mozilla/5.0 (compatible; Sun2FraudCheck/1.0)',
            ]);
    }

    private function extractCsrfToken(string $html): ?string
    {
        if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/value="([^"]+)"\s+name="_token"/', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function cookieMap(mixed $cookieJar): array
    {
        $cookies = [];

        if (! is_object($cookieJar) || ! method_exists($cookieJar, 'toArray')) {
            return $cookies;
        }

        foreach ($cookieJar->toArray() as $cookie) {
            $name = $cookie['Name'] ?? $cookie['name'] ?? null;
            $value = $cookie['Value'] ?? $cookie['value'] ?? null;

            if (is_string($name) && $name !== '' && is_string($value)) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{
     *     total_parcels: int,
     *     total_delivered: int,
     *     total_cancelled: int,
     *     total_fraud_reports: mixed,
     *     success_ratio: int
     * }
     */
    private function normalizeFraudResponse(array $response): array
    {
        $payload = $response;

        if (isset($response['data']) && is_array($response['data'])) {
            $payload = array_merge($response, $response['data']);
        }

        $lower = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $lower[strtolower($key)] = $value;
            }
        }

        $totalDelivered = (int) ($lower['total_delivered'] ?? 0);
        $totalCancelled = (int) ($lower['total_cancelled'] ?? 0);
        $totalParcels = (int) ($lower['total_parcels'] ?? 0);

        if ($totalParcels <= 0 && ($totalDelivered > 0 || $totalCancelled > 0)) {
            $totalParcels = $totalDelivered + $totalCancelled;
        }

        $successRatio = $totalParcels > 0
            ? (int) round(($totalDelivered / $totalParcels) * 100)
            : (int) ($lower['success_ratio'] ?? 0);

        return [
            'total_parcels' => $totalParcels,
            'total_delivered' => $totalDelivered,
            'total_cancelled' => $totalCancelled,
            'total_fraud_reports' => $lower['total_fraud_reports'] ?? [],
            'success_ratio' => $successRatio,
        ];
    }

    private function fraudCheckPhoneDigits(string $phone): string
    {
        $display = PhoneNumber::extractFirstBangladeshMobile($phone) ?? PhoneNumber::display($phone);
        $digits = preg_replace('/\D+/', '', $display) ?? '';

        if ($digits === '' || ! PhoneNumber::isValidDisplayMobile($digits)) {
            throw new RuntimeException('A valid phone number is required for fraud check.');
        }

        return $digits;
    }

    private function hasApiCredentials(): bool
    {
        return filled(config('steadfast.api_key')) && filled(config('steadfast.secret_key'));
    }

    private function hasFraudPanelCredentials(): bool
    {
        return filled(config('steadfast.fraud.email')) && filled(config('steadfast.fraud.password'));
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = [], ?int $timeout = null): array
    {
        $apiKey = config('steadfast.api_key');
        $secretKey = config('steadfast.secret_key');

        if (! $apiKey || ! $secretKey) {
            throw new RuntimeException('Steadfast API credentials are not configured.');
        }

        $url = config('steadfast.base_url').$path;

        $pending = Http::timeout($timeout ?? (int) config('steadfast.timeout', 30))
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
