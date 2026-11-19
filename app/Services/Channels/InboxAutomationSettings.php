<?php

namespace App\Services\Channels;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class InboxAutomationSettings
{
    public const SETTING_KEY = 'channels.inbox.automations';

    public const PHOTO_REQUEST = 'photo_request';

    public const PRICED_IMAGE = 'priced_image';

    public const LOW_MATCH_WAIT = 'low_match_wait';

    public const HI_REPLY = 'hi_reply';

    public const COLOR_GUARANTEE = 'color_guarantee';

    public const DELIVERY_CHARGE = 'delivery_charge';

    public const LOCATION = 'location';

    /**
     * @var list<string>
     */
    public const KEYS = [
        self::PHOTO_REQUEST,
        self::PRICED_IMAGE,
        self::LOW_MATCH_WAIT,
        self::HI_REPLY,
        self::COLOR_GUARANTEE,
        self::DELIVERY_CHARGE,
        self::LOCATION,
    ];

    /**
     * @return array{photo_request: bool, priced_image: bool, low_match_wait: bool, hi_reply: bool, color_guarantee: bool, delivery_charge: bool, location: bool}
     */
    public function all(): array
    {
        $defaults = $this->defaults();
        $stored = Setting::getValue(self::SETTING_KEY);
        if (! is_string($stored) || $stored === '') {
            return $defaults;
        }

        $decoded = json_decode($stored, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        $out = $defaults;
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $out[$key] = (bool) $decoded[$key];
            }
        }

        return $out;
    }

    public function enabled(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    public function anyEnabled(): bool
    {
        return in_array(true, $this->all(), true);
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array{photo_request: bool, priced_image: bool, low_match_wait: bool, hi_reply: bool, color_guarantee: bool, delivery_charge: bool, location: bool}
     */
    public function save(array $flags): array
    {
        $normalized = $this->defaults();
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $flags)) {
                $normalized[$key] = (bool) $flags[$key];
            }
        }

        Setting::putValue(
            self::SETTING_KEY,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            'channels',
        );

        return $normalized;
    }

    /**
     * @return array{photo_request: bool, priced_image: bool, low_match_wait: bool, hi_reply: bool, color_guarantee: bool, delivery_charge: bool, location: bool}
     */
    public function defaults(): array
    {
        return [
            self::PHOTO_REQUEST => (bool) config('channels.inbox.auto_photo_request', true),
            self::PRICED_IMAGE => (bool) config('channels.inbox.auto_priced_image', false),
            self::LOW_MATCH_WAIT => (bool) config('channels.inbox.auto_low_match_wait', false),
            self::HI_REPLY => (bool) config('channels.inbox.auto_hi_reply', true),
            self::COLOR_GUARANTEE => (bool) config('channels.inbox.auto_color_guarantee', true),
            self::DELIVERY_CHARGE => (bool) config('channels.inbox.auto_delivery_charge', true),
            self::LOCATION => (bool) config('channels.inbox.auto_location', true),
        ];
    }

    public function forgetCache(): void
    {
        Cache::forget('setting:'.self::SETTING_KEY);
    }
}
