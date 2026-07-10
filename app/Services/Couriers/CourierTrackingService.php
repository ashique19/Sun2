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
     */
    public function fetchAndRecordLiveStatus(Order $order): ?string
    {
        $this->backfillFromLegacy($order);

        $status = $this->fetchLiveStatus($order);

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
     *
     * @return list<array{at:string,message:string,status:?string}>
     */
    public function trackingEvents(Order $order): array
    {
        $this->backfillFromLegacy($order);
        $order->unsetRelation('courierLogs');
        $order->load('courierLogs');

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
        $message = trim((string) ($data['tracking_message'] ?? ''));
        $status = $this->normalizeStatus($data['delivery_status'] ?? $data['status'] ?? null);
        $rawAt = $data['updated_at'] ?? $fallbackAt;

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
            $rawAt = $consignment['updated_at'] ?? $consignment['created_at'] ?? $rawAt;
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
                'updated_at' => now(self::DHAKA_TZ)->format('Y-m-d H:i:s'),
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

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            if (str_contains($value, 'T') || str_ends_with($value, 'Z')) {
                return Carbon::parse($value);
            }

            return Carbon::parse($value, self::DHAKA_TZ);
        } catch (Throwable) {
            return null;
        }
    }
}
