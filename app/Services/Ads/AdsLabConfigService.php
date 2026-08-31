<?php

namespace App\Services\Ads;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class AdsLabConfigService
{
    public const SETTING_KEY = 'ads.lab.units';

    public const SETTING_GROUP = 'ads';

    /**
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    public function payload(): array
    {
        $stored = $this->decodeStored();

        if ($stored !== null) {
            return $stored;
        }

        return $this->defaults();
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }>
     */
    public function units(): array
    {
        $payload = $this->payload();

        $banners = collect($payload['banners'])
            ->map(fn (array $slot, string $key): array => $this->normalizeUnit($key, $slot));

        $scripts = collect($payload['scripts'])
            ->map(fn (array $slot, string $key): array => $this->normalizeUnit($key, $slot));

        return $banners->merge($scripts)->values()->all();
    }

    /**
     * Resolve one configured unit by id (e.g. banner_728) for storefront placements.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }|null
     */
    public function unit(string $id): ?array
    {
        foreach ($this->units() as $unit) {
            if ($unit['key'] === $id) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Leaderboard for product page — only when the unit is live (has a slot key).
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }|null
     */
    public function productAfterDescriptionLeaderboard(): ?array
    {
        if (! config('ads.placements.product_after_description', true)) {
            return null;
        }

        $unit = $this->unit('banner_728');

        if ($unit === null || $unit['type'] !== 'atoptions' || ! filled($unit['slot_key'])) {
            return null;
        }

        return $unit;
    }

    /**
     * Storefront popunder — smartlink URL and/or network script from settings.
     *
     * @return array{url: ?string, script_src: ?string}|null
     */
    public function storefrontPopunder(): ?array
    {
        if (! config('ads.placements.popunder', false)) {
            return null;
        }

        if ($this->requestExcludesPopunder()) {
            return null;
        }

        $unit = $this->unit('popunder');

        if ($unit === null) {
            return null;
        }

        $url = filled($unit['smartlink_url'] ?? null) ? (string) $unit['smartlink_url'] : null;
        $scriptSrc = filled($unit['script_src'] ?? null) ? (string) $unit['script_src'] : null;

        if ($url === null && $scriptSrc === null) {
            return null;
        }

        return [
            'url' => $url,
            'script_src' => $scriptSrc,
        ];
    }

    /**
     * Exit interstitial smartlink (back / exit-intent modal — not true tab close).
     */
    public function storefrontExitInterstitialUrl(): ?string
    {
        if (! config('ads.placements.exit_interstitial', false)) {
            return null;
        }

        if ($this->requestExcludesPopunder()) {
            return null;
        }

        $unit = $this->unit('exit_smartlink');

        if ($unit === null || ! filled($unit['smartlink_url'] ?? null)) {
            return null;
        }

        return (string) $unit['smartlink_url'];
    }

    /**
     * Merge missing default script/banner keys into the stored settings payload.
     *
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    public function mergeMissingDefaults(): array
    {
        $current = $this->payload();
        $defaults = $this->defaults();
        $changed = false;

        foreach ($defaults['banners'] as $key => $slot) {
            if (! array_key_exists($key, $current['banners'])) {
                $current['banners'][$key] = $slot;
                $changed = true;
            }
        }

        foreach ($defaults['scripts'] as $key => $slot) {
            if (! array_key_exists($key, $current['scripts'])) {
                $current['scripts'][$key] = $slot;
                $changed = true;
            }
        }

        if ($changed) {
            return $this->save($current);
        }

        return $current;
    }

    public function requestExcludesPopunder(): bool
    {
        $patterns = array_values(array_filter(
            (array) config('ads.popunder_excluded_routes', []),
            fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));

        if ($patterns === [] || request()->route() === null) {
            return false;
        }

        return request()->routeIs(...$patterns);
    }

    public function invokeHost(): string
    {
        return $this->payload()['invoke_host'];
    }

    public function network(): string
    {
        return $this->payload()['network'];
    }

    /**
     * @param  array{
     *     invoke_host?: mixed,
     *     network?: mixed,
     *     banners?: mixed,
     *     scripts?: mixed
     * }  $payload
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    public function save(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        Setting::putValue(
            self::SETTING_KEY,
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::SETTING_GROUP,
        );

        return $normalized;
    }

    /**
     * Seed DB from config defaults when the setting is missing.
     *
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    public function seedFromDefaultsIfMissing(): array
    {
        if ($this->decodeStored() !== null) {
            return $this->payload();
        }

        return $this->save($this->defaults());
    }

    public function clearStored(): void
    {
        Setting::query()->where('key', self::SETTING_KEY)->delete();
        Cache::forget('setting:'.self::SETTING_KEY);
    }

    /**
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    public function defaults(): array
    {
        return $this->normalizePayload([
            'invoke_host' => config('ads.invoke_host'),
            'network' => config('ads.network'),
            'banners' => config('ads.default_banners', []),
            'scripts' => config('ads.default_scripts', []),
        ]);
    }

    /**
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }|null
     */
    private function decodeStored(): ?array
    {
        $raw = Setting::getValue(self::SETTING_KEY);

        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $this->normalizePayload($decoded);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     invoke_host: string,
     *     network: string,
     *     banners: array<string, array<string, mixed>>,
     *     scripts: array<string, array<string, mixed>>
     * }
     */
    private function normalizePayload(array $payload): array
    {
        $banners = [];
        foreach ((array) ($payload['banners'] ?? []) as $key => $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $banners[(string) $key] = $slot;
        }

        $scripts = [];
        foreach ((array) ($payload['scripts'] ?? []) as $key => $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $scripts[(string) $key] = $slot;
        }

        return [
            'invoke_host' => filled($payload['invoke_host'] ?? null)
                ? (string) $payload['invoke_host']
                : (string) config('ads.invoke_host', 'www.highrevenueformat.com'),
            'network' => filled($payload['network'] ?? null)
                ? (string) $payload['network']
                : (string) config('ads.network', 'adsterra'),
            'banners' => $banners,
            'scripts' => $scripts,
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: string,
     *     slot_key: ?string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     script_src: ?string,
     *     smartlink_url: ?string
     * }
     */
    private function normalizeUnit(string $key, array $slot): array
    {
        return [
            'key' => $key,
            'label' => (string) ($slot['label'] ?? $key),
            'description' => (string) ($slot['description'] ?? ''),
            'type' => (string) ($slot['type'] ?? 'atoptions'),
            'slot_key' => filled($slot['key'] ?? null) ? (string) $slot['key'] : null,
            'width' => (int) ($slot['width'] ?? 300),
            'height' => (int) ($slot['height'] ?? 250),
            'format' => (string) ($slot['format'] ?? 'iframe'),
            'script_src' => filled($slot['script_src'] ?? $slot['src'] ?? null)
                ? (string) ($slot['script_src'] ?? $slot['src'])
                : null,
            'smartlink_url' => filled($slot['url'] ?? null) ? (string) $slot['url'] : null,
        ];
    }
}
