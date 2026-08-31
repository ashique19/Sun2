<?php

namespace App\Services\Ads;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class AdsLabConfigService
{
    public const SETTING_KEY = 'ads.lab.units';

    public const PLACEMENTS_SETTING_KEY = 'ads.placements';

    public const SETTING_GROUP = 'ads';

    /**
     * Default placement toggles from config/env (used when no admin override is saved).
     *
     * @return array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }
     */
    public function placementDefaults(): array
    {
        return [
            'product_after_description' => (bool) config('ads.placements.product_after_description', true),
            'product_video' => (bool) config('ads.placements.product_video', true),
            'product_video_src' => trim((string) config('ads.product_video_src', '')),
            'popunder' => (bool) config('ads.placements.popunder', true),
            'exit_interstitial' => (bool) config('ads.placements.exit_interstitial', true),
            'lab_enabled' => (bool) config('ads.lab_enabled', true),
        ];
    }

    /**
     * Effective placements: admin Settings override, else config/env defaults.
     *
     * @return array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }
     */
    public function placements(): array
    {
        $defaults = $this->placementDefaults();
        $raw = Setting::getValue(self::PLACEMENTS_SETTING_KEY);

        if ($raw === null || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $defaults;
        }

        return $this->normalizePlacements(array_merge($defaults, $decoded));
    }

    public function placementEnabled(string $key): bool
    {
        $placements = $this->placements();

        return (bool) ($placements[$key] ?? false);
    }

    public function labEnabled(): bool
    {
        return $this->placementEnabled('lab_enabled');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }
     */
    public function savePlacements(array $input): array
    {
        $normalized = $this->normalizePlacements(array_merge($this->placementDefaults(), $input));

        Setting::putValue(
            self::PLACEMENTS_SETTING_KEY,
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::SETTING_GROUP,
        );

        return $normalized;
    }

    /**
     * @return array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }
     */
    public function resetPlacementsToDefaults(): array
    {
        Setting::query()->where('key', self::PLACEMENTS_SETTING_KEY)->delete();
        Cache::forget('setting:'.self::PLACEMENTS_SETTING_KEY);

        return $this->placementDefaults();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     product_after_description: bool,
     *     product_video: bool,
     *     product_video_src: string,
     *     popunder: bool,
     *     exit_interstitial: bool,
     *     lab_enabled: bool
     * }
     */
    public function normalizePlacements(array $input): array
    {
        $src = trim((string) ($input['product_video_src'] ?? ''));
        if (mb_strlen($src) > 2000) {
            $src = mb_substr($src, 0, 2000);
        }

        return [
            'product_after_description' => (bool) ($input['product_after_description'] ?? false),
            'product_video' => (bool) ($input['product_video'] ?? false),
            'product_video_src' => $src,
            'popunder' => (bool) ($input['popunder'] ?? false),
            'exit_interstitial' => (bool) ($input['exit_interstitial'] ?? false),
            'lab_enabled' => (bool) ($input['lab_enabled'] ?? false),
        ];
    }

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
        if (! $this->placementEnabled('product_after_description')) {
            return null;
        }

        $unit = $this->unit('banner_728');

        if ($unit === null || $unit['type'] !== 'atoptions' || ! filled($unit['slot_key'])) {
            return null;
        }

        return $unit;
    }

    /**
     * Compact mobile strip for product page — shown below the md breakpoint.
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
    public function productAfterDescriptionMobileBanner(): ?array
    {
        if (! $this->placementEnabled('product_after_description')) {
            return null;
        }

        $unit = $this->unit('banner_320');

        if ($unit === null || $unit['type'] !== 'atoptions' || ! filled($unit['slot_key'])) {
            return null;
        }

        return $unit;
    }

    /**
     * HilltopAds (or similar) video loader — product detail pages only.
     */
    public function productVideoAdSrc(): ?string
    {
        if (! $this->placementEnabled('product_video')) {
            return null;
        }

        $src = trim((string) ($this->placements()['product_video_src'] ?? ''));

        return $src !== '' ? $src : null;
    }

    /**
     * Storefront popunder — smartlink URL and/or network script from settings.
     *
     * @return array{url: ?string, script_src: ?string}|null
     */
    public function storefrontPopunder(): ?array
    {
        if (! $this->placementEnabled('popunder')) {
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
     * Exit interstitial smartlink URL when the placement is enabled (ignores route exclusions).
     * Route exclusions are enforced in the Blade/Alpine host so the layout-mounted
     * component can persist across Livewire wire:navigate.
     */
    public function storefrontExitInterstitialUrl(): ?string
    {
        if (! $this->placementEnabled('exit_interstitial')) {
            return null;
        }

        $unit = $this->unit('exit_smartlink');

        if ($unit === null || ! filled($unit['smartlink_url'] ?? null)) {
            return null;
        }

        return (string) $unit['smartlink_url'];
    }

    /**
     * Path prefixes where exit interstitial triggers must not run (client-side gate).
     *
     * @return list<string>
     */
    public function exitInterstitialExcludedPathPrefixes(): array
    {
        return [
            '/cart',
            '/checkout',
            '/login',
            '/register',
            '/forgot-password',
            '/reset-password',
            '/account',
            '/admin',
            '/reseller',
            '/ads-lab',
        ];
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
