<?php

namespace App\Services\Couriers;

use App\Models\CourierData;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CourierTrackingService
{
    public const DHAKA_TZ = 'Asia/Dhaka';

    /** @var array<int, true> */
    private array $legacyBackfilled = [];

    public function __construct(
        private readonly SteadfastApiClient $steadfast,
        private readonly CourierApiRegistry $registry,
    ) {}

    /**
     * Live delivery status from the courier API when available.
     */
    public function fetchLiveStatus(Order $order): ?string
    {
        $slug = strtolower((string) ($order->courier?->slug ?? ''));

        if ($slug === '' || ! $this->registry->isConfigured($slug)) {
            return null;
        }

        try {
            return match ($slug) {
                'steadfast' => $this->fetchSteadfastStatus($order),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fetch live status and persist a tracking snapshot when it changes (or when none exist yet).
     *
     * @param  bool  $backfillLegacy  Skip on list polls — legacy copy is slow and not needed to paint live status.
     */
    public function fetchAndRecordLiveStatus(Order $order, bool $backfillLegacy = true): ?string
    {
        if ($backfillLegacy) {
            $this->backfillFromLegacy($order);
        }

        $status = $this->fetchLiveStatus($order);

        if (strtolower((string) ($order->courier?->slug ?? '')) === 'steadfast') {
            $this->persistSteadfastTrackingHistory($order);
        }

        if (filled($status)) {
            $this->recordStatusSnapshot($order, $status);
            $order->unsetRelation('courierLogs');
        }

        return $status;
    }

    /**
     * Prefer live API status, else latest stored courier status.
     */
    public function displayStatus(Order $order, ?string $liveStatus = null): ?string
    {
        if (filled($liveStatus)) {
            return strtolower(trim($liveStatus));
        }

        return $this->latestStoredStatus($order);
    }

    /**
     * Tracking timeline for display (newest first), times in Asia/Dhaka.
     * Uses already-loaded courierLogs when present; does not hit courier APIs or legacy DB.
     *
     * @return list<array{at:string,message:string,status:?string}>
     */
    public function eventsFromLoadedLogs(Order $order): array
    {
        if (! $order->relationLoaded('courierLogs')) {
            $order->load('courierLogs');
        }

        $events = [];
        $seen = [];

        foreach ($order->courierLogs as $log) {
            $data = $this->normalizeApiData($log->api_data);
            $extracted = $this->extractEvent($data, $log->created_at);

            if ($extracted === null) {
                continue;
            }

            $key = $extracted['at'].'|'.mb_strtolower($extracted['message']);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $events[] = $extracted;
        }

        usort($events, fn (array $a, array $b) => $b['sort'] <=> $a['sort']);

        return array_map(
            fn (array $event) => [
                'at' => $event['at'],
                'message' => $event['message'],
                'status' => $event['status'],
            ],
            $events,
        );
    }

    /**
     * Tracking timeline with optional legacy backfill (for explicit refresh paths).
     *
     * @return list<array{at:string,message:string,status:?string}>
     */
    public function trackingEvents(Order $order): array
    {
        $this->backfillFromLegacy($order);
        $order->unsetRelation('courierLogs');
        $order->load('courierLogs');

        return $this->eventsFromLoadedLogs($order);
    }

    public function formatDhaka(mixed $value): string
    {
        $carbon = $this->parseTimestamp($value);

        if (! $carbon) {
            return '—';
        }

        return $carbon->timezone(self::DHAKA_TZ)->format('d-M H:i');
    }

    /**
     * Copy missing courier webhook history from the legacy database.
     */
    private function backfillFromLegacy(Order $order): void
    {
        if (isset($this->legacyBackfilled[$order->id])) {
            return;
        }

        $this->legacyBackfilled[$order->id] = true;

        try {
            if (! Schema::connection('legacy')->hasTable('courier_data')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $hasWebhookHistory = CourierData::query()
            ->where('order_id', $order->id)
            ->get(['api_data'])
            ->contains(function (CourierData $log) {
                $data = $this->normalizeApiData($log->api_data);

                return ($data['source'] ?? null) !== 'status_poll'
                    && filled($data['tracking_message'] ?? null);
            });

        if ($hasWebhookHistory) {
            return;
        }

        try {
            $rows = DB::connection('legacy')
                ->table('courier_data')
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $apiData = $this->normalizeApiData($row->api_data ?? null);

            if ($apiData === []) {
                continue;
            }

            CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $row->courier_id ?: $order->courier_id,
                'api_data' => $apiData,
                'created_at' => $row->created_at ?? now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeApiData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{at:string,message:string,status:?string,sort:int}|null
     */
    private function extractEvent(array $data, mixed $fallbackAt): ?array
    {
        // Live status polls are stamped when we asked Packzy, not when the
        // parcel moved. Keep them off the timeline.
        if (($data['source'] ?? null) === 'status_poll') {
            return null;
        }

        $message = SteadfastWebhookPayload::trackingMessage($data);
        $status = $this->normalizeStatus($data['delivery_status'] ?? $data['status_type'] ?? $data['status'] ?? null);
        $rawAt = $this->payloadTimestamp($data) ?? $fallbackAt;

        // create_order / API booking payload
        if (isset($data['consignment']) && is_array($data['consignment'])) {
            $consignment = $data['consignment'];
            $status = $this->normalizeStatus($consignment['status'] ?? $status);
            if ($message === '') {
                $message = trim((string) ($data['message'] ?? ''));
            }
            if ($message === '' && $status !== null) {
                $message = 'Consignment status: '.$status;
            }
            $rawAt = $this->payloadTimestamp($consignment) ?? $rawAt;
        }

        if ($message === '' && $status !== null) {
            $message = 'Consignment status has been updated as '.$this->humanizeStatus($status);
        }

        if ($message === '') {
            return null;
        }

        return [
            'at' => $this->formatDhaka($rawAt),
            'message' => $message,
            'status' => $status,
            'sort' => $this->parseTimestamp($rawAt)?->timestamp ?? 0,
        ];
    }

    private function recordStatusSnapshot(Order $order, string $status): void
    {
        $status = strtolower(trim($status));
        $existingEvents = $this->trackingEventsWithoutBackfill($order);
        $latest = $this->latestStoredStatus($order);

        if ($latest === $status && $existingEvents !== []) {
            return;
        }

        CourierData::query()->create([
            'order_id' => $order->id,
            'courier_id' => $order->courier_id,
            'api_data' => [
                'notification_type' => 'delivery_status',
                'source' => 'status_poll',
                'invoice' => (string) $order->order_number,
                'tracking_id' => $order->courier_tracker,
                'status' => $status,
                'delivery_status' => $status,
                'tracking_message' => 'Consignment status has been updated as '.$this->humanizeStatus($status),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<array{at:string,message:string,status:?string}>
     */
    private function trackingEventsWithoutBackfill(Order $order): array
    {
        $order->loadMissing('courierLogs');

        $events = [];
        $seen = [];

        foreach ($order->courierLogs as $log) {
            $extracted = $this->extractEvent($this->normalizeApiData($log->api_data), $log->created_at);
            if ($extracted === null) {
                continue;
            }
            $key = $extracted['at'].'|'.mb_strtolower($extracted['message']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $events[] = $extracted;
        }

        return $events;
    }

    private function fetchSteadfastStatus(Order $order): ?string
    {
        $response = $this->steadfast->getStatusByInvoice((string) $order->order_number);
        $status = $this->normalizeStatus(
            $response['delivery_status'] ?? data_get($response, 'data.delivery_status')
        );

        if ($status === null && filled($order->courier_tracker)) {
            $tracker = (string) $order->courier_tracker;

            $byCidOrCode = ctype_digit($tracker)
                ? $this->steadfast->getStatusByConsignmentId($tracker)
                : $this->steadfast->getStatusByTrackingCode($tracker);

            $status = $this->normalizeStatus(
                $byCidOrCode['delivery_status'] ?? data_get($byCidOrCode, 'data.delivery_status')
            );
        }

        return $status ?? $this->latestStoredStatus($order);
    }

    private function latestStoredStatus(Order $order): ?string
    {
        $order->loadMissing('courierLogs');

        foreach ($order->courierLogs as $log) {
            $data = $this->normalizeApiData($log->api_data);
            $status = $this->normalizeStatus(
                $data['delivery_status']
                    ?? data_get($data, 'consignment.status')
                    ?? $data['status']
                    ?? null
            );

            if ($status !== null) {
                return $status;
            }
        }

        return null;
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (is_numeric($status)) {
            return null;
        }

        $normalized = strtolower(trim((string) $status));

        return $normalized !== '' ? $normalized : null;
    }

    private function humanizeStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Persist Packzy `/trackings_by_invoice` steps using each step's created_at
     * (when it happened), never the time we fetched the list.
     */
    private function persistSteadfastTrackingHistory(Order $order): void
    {
        $invoice = trim((string) $order->order_number);
        if ($invoice === '') {
            return;
        }

        try {
            $response = $this->steadfast->getTrackingsByInvoice($invoice);
        } catch (Throwable) {
            return;
        }

        $seen = [];
        $order->loadMissing('courierLogs');
        foreach ($order->courierLogs as $log) {
            $extracted = $this->extractEvent($this->normalizeApiData($log->api_data), null);
            if ($extracted === null) {
                continue;
            }
            $seen[$extracted['at'].'|'.mb_strtolower($extracted['message'])] = true;
        }

        $inserted = false;
        foreach ($this->trackingStepsFrom($response) as $step) {
            if (! is_array($step)) {
                continue;
            }

            $message = trim((string) ($step['text'] ?? $step['tracking_message'] ?? $step['message'] ?? ''));
            $occurredAt = $this->payloadTimestamp($step);
            if ($message === '' || $occurredAt === null) {
                continue;
            }

            $payload = [
                'notification_type' => SteadfastWebhookPayload::TYPE_TRACKING_UPDATE,
                'source' => 'tracking_poll',
                'consignment_id' => $step['consignment_id'] ?? $order->courier_consignment_id,
                'invoice' => $invoice,
                'tracking_message' => $message,
                'updated_at' => $occurredAt,
            ];

            $extracted = $this->extractEvent($payload, null);
            if ($extracted === null) {
                continue;
            }

            $key = $extracted['at'].'|'.mb_strtolower($extracted['message']);
            if (isset($seen[$key])) {
                continue;
            }

            CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $order->courier_id,
                'api_data' => $payload,
                'created_at' => $this->parseTimestamp($occurredAt) ?? now(),
            ]);

            $seen[$key] = true;
            $inserted = true;
        }

        if ($inserted) {
            $order->unsetRelation('courierLogs');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<mixed>
     */
    private function trackingStepsFrom(array $response): array
    {
        foreach (['tracking', 'trackings', 'data'] as $key) {
            $inner = $response[$key] ?? null;
            if (! is_array($inner) || $inner === []) {
                continue;
            }
            if (array_is_list($inner)) {
                return $inner;
            }
            foreach (['tracking', 'trackings'] as $nested) {
                $steps = $inner[$nested] ?? null;
                if (is_array($steps) && array_is_list($steps)) {
                    return $steps;
                }
            }
        }

        return array_is_list($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payloadTimestamp(array $data): mixed
    {
        foreach (['updated_at', 'timestamp', 'event_time', 'occurred_at', 'created_at'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($this->parseTimestamp($data[$key]) !== null) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * Courier payloads mix Eloquent UTC instants with Steadfast/Pathao wall-clock
     * strings. APP_TIMEZONE is UTC, so naive ISO `T` datetimes must not be
     * parsed as UTC — that shifts the blue timeline +6h vs the courier panel.
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_int($value) || (is_float($value) && $value == (int) $value)) {
            return $this->parseUnixTimestamp((int) $value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (preg_match('/^\d{9,13}$/', $value) === 1) {
                return $this->parseUnixTimestamp((int) $value);
            }

            // Honor explicit numeric offsets (+06:00, -00:00, +0600).
            if (preg_match('/[+-]\d{2}:?\d{2}$/', $value) === 1) {
                return Carbon::parse($value);
            }

            // Laravel/Steadfast JSON (`…000000Z`) is an honest UTC instant.
            if (str_ends_with(strtoupper($value), 'Z')) {
                return Carbon::parse($value);
            }

            // Naive wall-clock, including ISO `T` without an offset, is Dhaka.
            return Carbon::parse($value, self::DHAKA_TZ);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseUnixTimestamp(int $value): ?Carbon
    {
        if ($value <= 0) {
            return null;
        }

        if ($value > 1_000_000_000_000) {
            $value = (int) floor($value / 1000);
        }

        if ($value < 1_000_000_000) {
            return null;
        }

        return Carbon::createFromTimestamp($value);
    }
}
