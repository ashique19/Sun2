<?php

namespace App\Services\Couriers;

use App\Models\SteadfastWebhookLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class SteadfastWebhookLogService
{
    public const PAGE_SIZE = 50;

    public function capture(Request $request): SteadfastWebhookLog
    {
        $raw = (string) $request->getContent();
        $payload = $this->decode($raw);

        if ($payload === null) {
            $fallback = $request->all();
            $payload = is_array($fallback) && $fallback !== [] ? $fallback : null;
        }

        $payload = $this->redact($payload);
        $event = $this->primaryEvent($payload);

        return SteadfastWebhookLog::query()->create([
            'outcome' => SteadfastWebhookLog::OUTCOME_EMPTY,
            'http_status' => 200,
            'notification_type' => $this->stringValue(
                $event['original_notification_type']
                    ?? $event['notification_type']
                    ?? $event['event_type']
                    ?? $event['type']
                    ?? null
            ),
            'invoice' => $this->stringValue($event['invoice'] ?? null, allowNumeric: true),
            'consignment_id' => $this->stringValue($event['consignment_id'] ?? null, allowNumeric: true),
            'tracking_id' => $this->stringValue($event['tracking_id'] ?? $event['tracking_code'] ?? null, allowNumeric: true),
            'idempotency_key' => $this->header($request, 'Idempotency-Key'),
            'user_agent' => $this->header($request, 'User-Agent'),
            'content_type' => $this->header($request, 'Content-Type'),
            'has_signature' => $this->header($request, 'X-Signature') !== null,
            'raw_body' => $raw !== '' ? $raw : null,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function finalize(SteadfastWebhookLog $log, array $attributes): SteadfastWebhookLog
    {
        if (array_key_exists('error', $attributes) && is_string($attributes['error'])) {
            $attributes['error'] = mb_substr($attributes['error'], 0, 2000);
        }

        $log->forceFill($attributes)->save();

        return $log->fresh() ?? $log;
    }

    /**
     * @return LengthAwarePaginator<int, SteadfastWebhookLog>
     */
    public function paginate(int $perPage = self::PAGE_SIZE): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return SteadfastWebhookLog::query()
            ->with([
                'order:id,order_number,name,status,courier_tracker,courier_consignment_id',
            ])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $payload
     * @return array<string, mixed>|list<mixed>|null
     */
    private function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        if (array_is_list($payload)) {
            return array_map(function (mixed $item): mixed {
                return is_array($item) && ! array_is_list($item) ? $this->redactObject($item) : $item;
            }, $payload);
        }

        return $this->redactObject($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactObject(array $payload): array
    {
        foreach (['authorization', 'Authorization', 'token', 'api_token', 'webhook_token'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function primaryEvent(?array $payload): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        $events = SteadfastWebhookPayload::eventsFrom($payload);

        return $events[0] ?? (array_is_list($payload) ? [] : $payload);
    }

    private function header(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name, ''));

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private function stringValue(mixed $value, bool $allowNumeric = false): ?string
    {
        if (is_int($value) || (is_float($value) && $value == (int) $value)) {
            return $allowNumeric ? (string) (int) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
