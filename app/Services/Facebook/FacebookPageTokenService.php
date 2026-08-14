<?php

namespace App\Services\Facebook;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FacebookPageTokenService
{
    public const SETTING_KEY = 'facebook.page_access_token';

    public const USER_SETTING_KEY = 'facebook.user_access_token';

    public const EXPIRES_SETTING_KEY = 'facebook.page_access_token_expires_at';

    public const CACHE_KEY = 'facebook.page_token.status';

    /**
     * Effective Page access token: DB override wins over .env/config.
     */
    public function token(): string
    {
        $override = Setting::getValue(self::SETTING_KEY);

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return trim((string) config('facebook.messenger.page_access_token', ''));
    }

    public function pageId(): string
    {
        return trim((string) config('facebook.messenger.page_id', ''));
    }

    public function appId(): string
    {
        return trim((string) config('facebook.app_id', ''));
    }

    public function appSecret(): string
    {
        return trim((string) config('facebook.messenger.app_secret', ''));
    }

    public function graphVersion(): string
    {
        return (string) config('facebook.graph_version', 'v25.0');
    }

    public function generateTokenUrl(): string
    {
        return 'https://developers.facebook.com/tools/explorer/';
    }

    public function businessSystemUserUrl(): string
    {
        return 'https://business.facebook.com/settings/system-users';
    }

    /**
     * @return array{
     *     valid: bool,
     *     configured: bool,
     *     message: string,
     *     page_id: ?string,
     *     page_name: ?string,
     *     checked_at: string,
     *     expires_at: ?string,
     *     never_expires: ?bool
     * }
     */
    public function status(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function () {
            return $this->probe();
        });
    }

    public function invalidateStatusCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Validate and persist a Page access token.
     *
     * When FACEBOOK_APP_ID + FACEBOOK_APP_SECRET are set, the pasted token is sent
     * to Graph oauth/access_token (grant_type=fb_exchange_token). The returned
     * long-lived token is stored. User tokens are then converted into a Page token
     * for FACEBOOK_PAGE_ID (typically never expires). The long-lived User token is
     * kept so we can refresh before ~60 days.
     *
     * @return array{ok: bool, message: string, status: array<string, mixed>}
     */
    public function saveToken(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            return [
                'ok' => false,
                'message' => 'Token cannot be empty.',
                'status' => $this->status(true),
            ];
        }

        try {
            $normalized = $this->normalizeToPageToken($token);
        } catch (Throwable $e) {
            Log::warning('Facebook token normalization failed.', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'status' => $this->status(true),
            ];
        }

        $pageToken = $normalized['token'];
        $probe = $this->probe($pageToken);

        if (! $probe['valid']) {
            return [
                'ok' => false,
                'message' => $probe['message'],
                'status' => $probe,
            ];
        }

        $this->persistPageToken(
            $pageToken,
            $normalized['user_token'] ?? null,
            $normalized['expires_in'] ?? null,
        );
        $this->invalidateStatusCache();

        $status = $this->status(true);
        $message = $normalized['message'] ?? 'Page access token saved and verified.';

        if (! empty($status['never_expires'])) {
            $message .= ' Token does not expire.';
        } elseif (! empty($status['expires_at'])) {
            $message .= ' Token expires '.$status['expires_at'].'.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'status' => $status,
        ];
    }

    /**
     * Apply DB override into runtime config (boot helper).
     */
    public function applyRuntimeConfig(): void
    {
        $token = Setting::getValue(self::SETTING_KEY);

        if (is_string($token) && trim($token) !== '') {
            config(['facebook.messenger.page_access_token' => trim($token)]);
        }
    }

    /**
     * Re-exchange a stored long-lived User token (or the current Page token) and
     * persist the result. Safe to run from the scheduler.
     *
     * @return array{ok: bool, message: string}
     */
    public function refreshStoredToken(): array
    {
        $appId = $this->appId();
        $appSecret = $this->appSecret();

        if ($appId === '' || $appSecret === '') {
            return [
                'ok' => false,
                'message' => 'FACEBOOK_APP_ID and FACEBOOK_APP_SECRET are required to refresh tokens.',
            ];
        }

        $source = trim((string) Setting::getValue(self::USER_SETTING_KEY, ''));
        if ($source === '') {
            $source = $this->token();
        }

        if ($source === '') {
            return [
                'ok' => false,
                'message' => 'No Facebook token is stored to refresh.',
            ];
        }

        try {
            $normalized = $this->normalizeToPageToken($source);
        } catch (Throwable $e) {
            Log::warning('Facebook token refresh failed.', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }

        $probe = $this->probe($normalized['token']);
        if (! $probe['valid']) {
            return [
                'ok' => false,
                'message' => $probe['message'],
            ];
        }

        $this->persistPageToken(
            $normalized['token'],
            $normalized['user_token'] ?? null,
            $normalized['expires_in'] ?? null,
        );
        $this->invalidateStatusCache();

        return [
            'ok' => true,
            'message' => $normalized['message'],
        ];
    }

    /**
     * @return array{token: string, message: string, user_token: ?string, expires_in: ?int}
     */
    private function normalizeToPageToken(string $token): array
    {
        $appId = $this->appId();
        $appSecret = $this->appSecret();
        $pageId = $this->pageId();

        if ($appId === '' || $appSecret === '') {
            return [
                'token' => $token,
                'message' => 'Page access token saved and verified. Set FACEBOOK_APP_ID and FACEBOOK_APP_SECRET to auto-exchange tokens into long-lived Page tokens.',
                'user_token' => null,
                'expires_in' => null,
            ];
        }

        $debug = $this->debugToken($token, $appId, $appSecret);

        if (! ($debug['is_valid'] ?? false)) {
            $reason = is_string($debug['error'] ?? null) && $debug['error'] !== ''
                ? $debug['error']
                : 'Token is not valid according to Facebook debug_token.';

            throw new \RuntimeException($reason);
        }

        $type = strtoupper((string) ($debug['type'] ?? ''));
        $expiresAt = (int) ($debug['expires_at'] ?? 0);

        // Never-expiring Page tokens must not be exchanged — fb_exchange_token
        // would replace them with a ~60-day token.
        if ($type === 'PAGE' && $expiresAt === 0) {
            return [
                'token' => $token,
                'user_token' => null,
                'expires_in' => null,
                'message' => 'Never-expiring Page access token saved and verified.',
            ];
        }

        $working = $token;
        $expiresIn = null;
        $exchanged = false;

        try {
            $exchange = $this->exchangeForLongLivedToken($token, $appId, $appSecret);
            $working = $exchange['token'];
            $expiresIn = $exchange['expires_in'];
            $exchanged = true;
        } catch (Throwable $e) {
            Log::info('Facebook long-lived token exchange skipped.', ['message' => $e->getMessage()]);
        }

        if ($type === 'USER') {
            if ($pageId === '') {
                throw new \RuntimeException('FACEBOOK_PAGE_ID is required to convert a User token into a Page token.');
            }

            $pageToken = $this->pageAccessTokenFromUserToken($working, $pageId);

            return [
                'token' => $pageToken,
                'user_token' => $working,
                'expires_in' => $expiresIn,
                'message' => $exchanged
                    ? 'Exchanged for a long-lived User token, saved a never-expiring Page token, and stored the User token for automatic refresh.'
                    : 'Saved a never-expiring Page token from the User token and kept the User token for automatic refresh.',
            ];
        }

        if ($type === 'PAGE') {
            return [
                'token' => $working,
                'user_token' => null,
                'expires_in' => $expiresIn ?? ($expiresAt > 0 ? max(0, $expiresAt - time()) : null),
                'message' => $exchanged
                    ? 'Exchanged for a long-lived Page token and saved it on the server (~60 days). Paste a User token next time for a never-expiring Page token plus auto-refresh.'
                    : 'Page access token saved and verified. This Page token still expires — paste a User token (with Page permissions) to exchange it and enable auto-refresh.',
            ];
        }

        return [
            'token' => $working,
            'user_token' => null,
            'expires_in' => $expiresIn,
            'message' => $exchanged
                ? 'Exchanged for a long-lived token and saved it on the server.'
                : 'Page access token saved and verified.',
        ];
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     type: ?string,
     *     expires_at: int,
     *     error: ?string,
     *     app_id: ?string
     * }
     */
    private function debugToken(string $token, string $appId, string $appSecret): array
    {
        $version = $this->graphVersion();
        $appAccessToken = $appId.'|'.$appSecret;

        $response = Http::timeout(12)
            ->acceptJson()
            ->get('https://graph.facebook.com/'.$version.'/debug_token', [
                'input_token' => $token,
                'access_token' => $appAccessToken,
            ]);

        if (! $response->successful()) {
            return [
                'is_valid' => false,
                'type' => null,
                'expires_at' => 0,
                'error' => (string) data_get($response->json(), 'error.message', 'debug_token request failed.'),
                'app_id' => null,
            ];
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            return [
                'is_valid' => false,
                'type' => null,
                'expires_at' => 0,
                'error' => 'Unexpected debug_token response.',
                'app_id' => null,
            ];
        }

        return [
            'is_valid' => (bool) ($data['is_valid'] ?? false),
            'type' => isset($data['type']) ? (string) $data['type'] : null,
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'error' => isset($data['error']['message']) ? (string) $data['error']['message'] : null,
            'app_id' => isset($data['app_id']) ? (string) $data['app_id'] : null,
        ];
    }

    /**
     * @return array{token: string, expires_in: ?int}
     */
    private function exchangeForLongLivedToken(string $currentToken, string $appId, string $appSecret): array
    {
        $version = $this->graphVersion();

        $response = Http::timeout(12)
            ->acceptJson()
            ->get('https://graph.facebook.com/'.$version.'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $currentToken,
            ]);

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'error.message', 'Could not exchange token for a long-lived access token.');

            throw new \RuntimeException($message);
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new \RuntimeException('Facebook did not return a long-lived access token.');
        }

        $expiresIn = $response->json('expires_in');

        return [
            'token' => trim($accessToken),
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }

    private function persistPageToken(string $pageToken, ?string $userToken, ?int $expiresIn): void
    {
        Setting::putValue(self::SETTING_KEY, $pageToken, 'facebook');
        config(['facebook.messenger.page_access_token' => $pageToken]);
        $this->syncEnvFile($pageToken);

        if (is_string($userToken) && trim($userToken) !== '') {
            Setting::putValue(self::USER_SETTING_KEY, trim($userToken), 'facebook');
        }

        if ($expiresIn !== null && $expiresIn > 0) {
            Setting::putValue(
                self::EXPIRES_SETTING_KEY,
                now()->addSeconds($expiresIn)->toIso8601String(),
                'facebook',
            );
        } else {
            Setting::putValue(self::EXPIRES_SETTING_KEY, null, 'facebook');
        }
    }

    private function pageAccessTokenFromUserToken(string $userToken, string $pageId): string
    {
        $version = $this->graphVersion();

        $response = Http::timeout(12)
            ->acceptJson()
            ->get('https://graph.facebook.com/'.$version.'/'.$pageId, [
                'fields' => 'access_token,name',
                'access_token' => $userToken,
            ]);

        if (! $response->successful()) {
            $message = (string) data_get(
                $response->json(),
                'error.message',
                'Could not fetch a Page access token. Ensure the User token can manage this Page.',
            );

            throw new \RuntimeException($message);
        }

        $pageToken = $response->json('access_token');
        if (! is_string($pageToken) || trim($pageToken) === '') {
            throw new \RuntimeException('Facebook did not return a Page access token for PAGE_ID '.$pageId.'.');
        }

        return trim($pageToken);
    }

    /**
     * @return array{
     *     valid: bool,
     *     configured: bool,
     *     message: string,
     *     page_id: ?string,
     *     page_name: ?string,
     *     checked_at: string,
     *     expires_at: ?string,
     *     never_expires: ?bool
     * }
     */
    private function probe(?string $token = null): array
    {
        $token = $token ?? $this->token();
        $checkedAt = now()->toIso8601String();

        if ($token === '') {
            return [
                'valid' => false,
                'configured' => false,
                'message' => 'Facebook Page access token is not configured.',
                'page_id' => null,
                'page_name' => null,
                'checked_at' => $checkedAt,
                'expires_at' => null,
                'never_expires' => null,
            ];
        }

        $version = $this->graphVersion();
        $expectedPageId = $this->pageId();

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/me', [
                    'fields' => 'id,name',
                    'access_token' => $token,
                ]);

            if (! $response->successful()) {
                $message = (string) data_get($response->json(), 'error.message', 'Token validation failed.');

                return [
                    'valid' => false,
                    'configured' => true,
                    'message' => $message,
                    'page_id' => null,
                    'page_name' => null,
                    'checked_at' => $checkedAt,
                    'expires_at' => null,
                    'never_expires' => null,
                ];
            }

            $pageId = data_get($response->json(), 'id');
            $pageName = data_get($response->json(), 'name');
            $pageId = is_string($pageId) ? $pageId : null;
            $pageName = is_string($pageName) ? $pageName : null;

            if ($expectedPageId !== '' && $pageId !== null && $pageId !== $expectedPageId) {
                return [
                    'valid' => false,
                    'configured' => true,
                    'message' => 'Token is valid but belongs to Page ID '.$pageId.' (expected '.$expectedPageId.').',
                    'page_id' => $pageId,
                    'page_name' => $pageName,
                    'checked_at' => $checkedAt,
                    'expires_at' => null,
                    'never_expires' => null,
                ];
            }

            $expiry = $this->inspectExpiry($token);
            $message = $pageName
                ? 'Connected as '.$pageName.($pageId ? ' ('.$pageId.')' : '')
                : 'Page access token is valid.';

            if ($expiry['never_expires'] === true) {
                $message .= ' · never expires';
            } elseif (is_string($expiry['expires_at'])) {
                $message .= ' · expires '.$expiry['expires_at'];
            }

            return [
                'valid' => true,
                'configured' => true,
                'message' => $message,
                'page_id' => $pageId,
                'page_name' => $pageName,
                'checked_at' => $checkedAt,
                'expires_at' => $expiry['expires_at'],
                'never_expires' => $expiry['never_expires'],
            ];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'configured' => true,
                'message' => 'Could not reach Facebook Graph API: '.$e->getMessage(),
                'page_id' => null,
                'page_name' => null,
                'checked_at' => $checkedAt,
                'expires_at' => null,
                'never_expires' => null,
            ];
        }
    }

    /**
     * @return array{expires_at: ?string, never_expires: ?bool}
     */
    private function inspectExpiry(string $token): array
    {
        $appId = $this->appId();
        $appSecret = $this->appSecret();

        if ($appId === '' || $appSecret === '') {
            return ['expires_at' => null, 'never_expires' => null];
        }

        try {
            $debug = $this->debugToken($token, $appId, $appSecret);
            $expiresAt = (int) ($debug['expires_at'] ?? 0);

            if (! ($debug['is_valid'] ?? false)) {
                return ['expires_at' => null, 'never_expires' => null];
            }

            if ($expiresAt === 0) {
                return ['expires_at' => null, 'never_expires' => true];
            }

            return [
                'expires_at' => Carbon::createFromTimestamp($expiresAt)->toIso8601String(),
                'never_expires' => false,
            ];
        } catch (Throwable) {
            return ['expires_at' => null, 'never_expires' => null];
        }
    }

    private function syncEnvFile(string $token): void
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return;
        }

        $line = 'FACEBOOK_PAGE_ACCESS_TOKEN="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $token).'"';

        if (preg_match('/^FACEBOOK_PAGE_ACCESS_TOKEN=.*$/m', $contents) === 1) {
            $contents = preg_replace('/^FACEBOOK_PAGE_ACCESS_TOKEN=.*$/m', $line, $contents) ?? $contents;
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }
}
