<?php

namespace App\Services\Couriers;

/**
 * Steadfast's 2026 panel still documents a flat delivery-status push without
 * notification_type, and 4xx responses are never retried. Normalize every
 * inbound body into the shape the rest of the app already stores.
 */
class SteadfastWebhookPayload
{
    public const TYPE_DELIVERY_STATUS = 'delivery_status';

    public const TYPE_TRACKING_UPDATE = 'tracking_update';

    /** @var list<string> */
    private const WRAPPER_KEYS = ['data', 'payload', 'event', 'webhook', 'consignment', 'parcel', 'order'];

    /** @var list<string> */
    private const MESSAGE_KEYS = [
        'tracking_message',
        'message',
        'note',
        'details',
        'description',
        'tracking_note',
        'comment',
    ];

    /** @var list<string> */
    private const KNOWN_DELIVERY_STATUSES = [
        'pending',
        'delivered_approval_pending',
        'partial_delivered_approval_pending',
        'cancelled_approval_pending',
        'unknown_approval_pending',
        'delivered',
        'partial_delivered',
        'cancelled',
        'returned',
        'cancel and return',
        'hold',
        'in_review',
        'in review',
        'in_transit',
        'in transit',
        'processing',
        'unknown',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function eventsFrom(mixed $body): array
    {
        if (! is_array($body) || $body === []) {
            return [];
        }

        if (array_is_list($body)) {
            $events = [];
            foreach ($body as $item) {
                if (! is_array($item) || $item === []) {
                    continue;
                }
                $events[] = self::normalize($item);
            }

            return $events;
        }

        $unwrapped = self::unwrap($body);
        if (array_is_list($unwrapped)) {
            return self::eventsFrom($unwrapped);
        }

        return [self::normalize($unwrapped)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        $payload = self::unwrap($payload);
        if (array_is_list($payload)) {
            return $payload;
        }

        $eventName = is_string($payload['event'] ?? null) ? $payload['event'] : null;
        $originalType = strtolower(self::stringValue(
            $payload['notification_type'] ?? $payload['event_type'] ?? $payload['type'] ?? $eventName
        ));
        $message = self::trackingMessage($payload);
        $status = self::deliveryStatus($payload);

        if ($message === '') {
            $rawStatus = self::stringValue($payload['status'] ?? null);
            if ($rawStatus !== '' && ! self::isKnownDeliveryStatus($rawStatus)) {
                $message = $rawStatus;
            }
        }

        $type = $originalType;
        if ($type !== self::TYPE_DELIVERY_STATUS && $type !== self::TYPE_TRACKING_UPDATE) {
            $type = $status !== null
                ? self::TYPE_DELIVERY_STATUS
                : self::TYPE_TRACKING_UPDATE;
        }

        if ($message !== '') {
            $payload['tracking_message'] = $message;
        }

        if ($status !== null) {
            $currentStatus = self::stringValue($payload['status'] ?? null);
            if ($currentStatus === '' || ! self::isKnownDeliveryStatus($currentStatus)) {
                $payload['status'] = $status;
            }
        }

        if ($originalType !== '' && $originalType !== $type) {
            $payload['original_notification_type'] = $originalType;
        }
        $payload['notification_type'] = $type;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function trackingMessage(array $payload): string
    {
        foreach (self::MESSAGE_KEYS as $key) {
            $value = self::stringValue($payload[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        foreach (self::WRAPPER_KEYS as $nested) {
            $inner = $payload[$nested] ?? null;
            if (! is_array($inner) || array_is_list($inner)) {
                continue;
            }
            $value = self::trackingMessage($inner);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private static function unwrap(array $payload): array
    {
        $current = $payload;

        for ($i = 0; $i < 3; $i++) {
            $merged = false;
            foreach (self::WRAPPER_KEYS as $key) {
                $inner = $current[$key] ?? null;
                if (! is_array($inner) || $inner === []) {
                    continue;
                }
                if (array_is_list($inner)) {
                    return $inner;
                }
                if (! self::hasEventHints($inner)) {
                    continue;
                }
                $current = array_merge($current, $inner);
                $merged = true;
            }
            if (! $merged) {
                break;
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function hasEventHints(array $payload): bool
    {
        foreach ([
            'notification_type',
            'consignment_id',
            'invoice',
            'tracking_id',
            'tracking_code',
            'tracking_message',
            'status_type',
            'delivery_status',
            'message',
            'note',
        ] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function deliveryStatus(array $payload): ?string
    {
        foreach (['status_type', 'delivery_status', 'status'] as $key) {
            $value = self::stringValue($payload[$key] ?? null);
            if ($value !== '' && self::isKnownDeliveryStatus($value)) {
                return strtolower($value);
            }
        }

        return null;
    }

    private static function isKnownDeliveryStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::KNOWN_DELIVERY_STATUSES, true);
    }

    private static function stringValue(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        if (is_int($value)) {
            return '';
        }

        return trim($value);
    }
}
