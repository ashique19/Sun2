<?php

namespace App\Services\Facebook;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FacebookPageTokenService
{
    public const SETTING_KEY = 'facebook.page_access_token';

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
     *     checked_at: string
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
     * Validate and persist a new Page access token.
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

        $probe = $this->probe($token);

        if (! $probe['valid']) {
            return [
                'ok' => false,
                'message' => $probe['message'],
                'status' => $probe,
            ];
        }

        Setting::putValue(self::SETTING_KEY, $token, 'facebook');
        config(['facebook.messenger.page_access_token' => $token]);
        $this->syncEnvFile($token);
        $this->invalidateStatusCache();

        $status = $this->status(true);

        return [
            'ok' => true,
            'message' => 'Page access token saved and verified.',
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
     * @return array{
     *     valid: bool,
     *     configured: bool,
     *     message: string,
     *     page_id: ?string,
     *     page_name: ?string,
     *     checked_at: string
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
                ];
            }

            return [
                'valid' => true,
                'configured' => true,
                'message' => $pageName
                    ? 'Connected as '.$pageName.($pageId ? ' ('.$pageId.')' : '')
                    : 'Page access token is valid.',
                'page_id' => $pageId,
                'page_name' => $pageName,
                'checked_at' => $checkedAt,
            ];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'configured' => true,
                'message' => 'Could not reach Facebook Graph API: '.$e->getMessage(),
                'page_id' => null,
                'page_name' => null,
                'checked_at' => $checkedAt,
            ];
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
