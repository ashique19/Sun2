<?php

namespace App\Services\Couriers;

use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
     * Prefers the merchant panel when STEADFAST_EMAIL / STEADFAST_PASSWORD are set
     * (Packzy `/fraud_check` now often returns empty zeros). Falls back to the API
     * only when the panel is unavailable or fails.
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
        $errors = [];

        if ($this->hasFraudPanelCredentials()) {
            try {
                return $this->fraudCheckViaPanel($digits);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($this->hasApiCredentials()) {
            try {
                return $this->fraudCheckViaApi($digits);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
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
    private function fraudCheckViaApi(string $phoneDigits): array
    {
        $response = $this->request('get', '/fraud_check/'.$phoneDigits);

        if (! $this->hasFraudCountKeys($response)) {
            throw new RuntimeException('Steadfast API fraud_check response was missing delivery counts.');
        }

        return $this->normalizeFraudResponse($response);
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

        $loginPage = $this->panelBrowserClient($panelUrl)->get('/login');

        if (! $loginPage->successful()) {
            throw new RuntimeException('Steadfast fraud panel login page is unavailable.');
        }

        $csrfToken = $this->extractCsrfToken($loginPage->body());

        if ($csrfToken === null) {
            throw new RuntimeException('Steadfast fraud panel CSRF token was not found.');
        }

        $preLoginCookies = $this->cookieMap($loginPage->cookies());

        // Do not follow the 302 — the authenticated session cookie is set on that response.
        $loginResponse = $this->panelBrowserClient($panelUrl)
            ->withCookies($preLoginCookies, $domain)
            ->withHeaders([
                'Referer' => $panelUrl.'/login',
                'Origin' => $panelUrl,
            ])
            ->asForm()
            ->withoutRedirecting()
            ->post('/login', [
                '_token' => $csrfToken,
                'email' => $email,
                'password' => $password,
            ]);

        $location = (string) $loginResponse->header('Location');

        if (! $loginResponse->redirect() || str_contains($location, '/login')) {
            throw new RuntimeException('Steadfast fraud panel login failed. Check STEADFAST_EMAIL / STEADFAST_PASSWORD.');
        }

        $sessionCookies = array_merge($preLoginCookies, $this->cookieMap($loginResponse->cookies()));

        try {
            return $this->fetchPanelFraudStats($panelUrl, $domain, $sessionCookies, $phoneDigits);
        } finally {
            $this->logoutFromPanel($panelUrl, $domain, $sessionCookies);
        }
    }

    /**
     * @param  array<string, string>  $cookies
     * @return array{
     *     total_parcels: int,
     *     total_delivered: int,
     *     total_cancelled: int,
     *     total_fraud_reports: mixed,
     *     success_ratio: int
     * }
     */
    private function fetchPanelFraudStats(string $panelUrl, string $domain, array $cookies, string $phoneDigits): array
    {
        $paths = [
            '/user/frauds/check/'.$phoneDigits,
            '/user/consignment/getbyphone/'.$phoneDigits,
        ];

        $errors = [];

        foreach ($paths as $path) {
            $response = $this->panelJsonClient($panelUrl)
                ->withCookies($cookies, $domain)
                ->withHeaders([
                    'Referer' => $panelUrl.'/user/frauds/check',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get($path);

            if (! $response->successful()) {
                $errors[] = $path.' HTTP '.$response->status();

                continue;
            }

            if (! $this->isJsonFraudResponse($response)) {
                $errors[] = $path.' returned non-JSON (likely an unauthenticated redirect)';

                continue;
            }

            /** @var array<string, mixed> $json */
            $json = $response->json();

            if (! $this->hasFraudCountKeys($json)) {
                $errors[] = $path.' missing delivery count keys';

                continue;
            }

            return $this->normalizeFraudResponse($json);
        }

        throw new RuntimeException(
            'Steadfast fraud panel returned no usable stats ('.implode('; ', $errors).').'
        );
    }

    /**
     * @param  array<string, string>  $cookies
     */
    private function logoutFromPanel(string $panelUrl, string $domain, array $cookies): void
    {
        try {
            $page = $this->panelBrowserClient($panelUrl)
                ->withCookies($cookies, $domain)
                ->get('/user/frauds/check');

            if (! $page->successful()) {
                return;
            }

            $csrfToken = $this->extractCsrfToken($page->body());

            if ($csrfToken === null) {
                return;
            }

            $this->panelBrowserClient($panelUrl)
                ->withCookies($cookies, $domain)
                ->asForm()
                ->post('/logout', [
                    '_token' => $csrfToken,
                ]);
        } catch (\Throwable) {
            // Best-effort logout; fraud data already retrieved.
        }
    }

    private function panelBrowserClient(string $panelUrl): PendingRequest
    {
        return Http::baseUrl($panelUrl)
            ->timeout((int) config('steadfast.timeout', 30))
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]);
    }

    private function panelJsonClient(string $panelUrl): PendingRequest
    {
        return Http::baseUrl($panelUrl)
            ->timeout((int) config('steadfast.timeout', 30))
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]);
    }

    private function isJsonFraudResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'json')) {
            return is_array($response->json());
        }

        // Some panel endpoints omit Content-Type; accept real JSON bodies only.
        return is_array($response->json());
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
     */
    private function hasFraudCountKeys(array $response): bool
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

        return array_key_exists('total_delivered', $lower)
            && array_key_exists('total_cancelled', $lower);
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

        if ($totalParcels <= 0) {
            $totalParcels = $totalDelivered + $totalCancelled;
        }

        $successRatio = $totalParcels > 0
            ? (int) round(($totalDelivered / $totalParcels) * 100)
            : (int) ($lower['success_ratio'] ?? 0);

        return [
            'total_parcels' => $totalParcels,
            'total_delivered' => $totalDelivered,
            'total_cancelled' => $totalCancelled,
            'total_fraud_reports' => $lower['total_fraud_reports'] ?? ($lower['frauds'] ?? []),
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
