<?php

namespace App\Services\Admin;

use App\Models\Courier;
use App\Models\CourierData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Latest inbound Steadfast webhook payloads for the admin dashboard inbox.
 */
class SteadfastWebhookInboxService
{
    public const MAX_ENTRIES = 20;

    public const WITHIN_DAYS = 2;

    public const PAGE_SIZE = 50;

    /**
     * Latest webhook row per order, newest first, capped for the dashboard.
     *
     * @return Collection<int, CourierData>
     */
    public function latestIncoming(int $limit = self::MAX_ENTRIES, int $withinDays = self::WITHIN_DAYS): Collection
    {
        $limit = max(1, min($limit, self::MAX_ENTRIES));

        return $this->latestIncomingQuery($withinDays)
            ->limit($limit)
            ->get();
    }

    /**
     * Same inbox rows as the dashboard, without the dashboard cap.
     *
     * @return LengthAwarePaginator<int, CourierData>
     */
    public function paginateIncoming(int $perPage = self::PAGE_SIZE, int $withinDays = self::WITHIN_DAYS): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return $this->latestIncomingQuery($withinDays)
            ->paginate($perPage);
    }

    public function dismiss(CourierData $entry): void
    {
        if ($entry->inbox_dismissed_at) {
            return;
        }

        $entry->forceFill([
            'inbox_dismissed_at' => now(),
        ])->save();
    }

    /**
     * Human-readable summary of a stored webhook payload.
     */
    public function summary(CourierData $entry): string
    {
        $payload = is_array($entry->api_data) ? $entry->api_data : [];
        $type = strtolower((string) ($payload['notification_type'] ?? ''));

        if ($type === 'delivery_status') {
            $status = trim((string) ($payload['status'] ?? $payload['delivery_status'] ?? ''));

            return $status !== '' ? $status : 'delivery status update';
        }

        if ($type === 'tracking_update') {
            $message = trim((string) ($payload['tracking_message'] ?? ''));

            return $message !== '' ? $message : 'tracking update';
        }

        return $type !== '' ? $type : 'webhook update';
    }

    /**
     * @return Builder<CourierData>
     */
    private function latestIncomingQuery(int $withinDays = self::WITHIN_DAYS): Builder
    {
        $withinDays = max(1, min($withinDays, self::WITHIN_DAYS));
        $since = now()->subDays($withinDays);

        $latestIdsQuery = CourierData::query()
            ->selectRaw('MAX(id) as id')
            ->where('created_at', '>=', $since)
            ->tap(fn (Builder $query) => $this->constrainToIncomingWebhooks($query))
            ->groupBy('order_id');

        return CourierData::query()
            ->with([
                'order:id,order_number,name,status,courier_id,courier_tracker,courier_consignment_id',
                'order.courier:id,name,slug',
            ])
            ->whereIn('id', $latestIdsQuery)
            ->whereNull('inbox_dismissed_at')
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * @param  Builder<CourierData>  $query
     * @return Builder<CourierData>
     */
    private function constrainToIncomingWebhooks(Builder $query): Builder
    {
        $steadfastId = Courier::query()->where('slug', 'steadfast')->value('id');

        if ($steadfastId) {
            $query->where('courier_id', $steadfastId);
        }

        return $query
            ->whereIn('api_data->notification_type', ['delivery_status', 'tracking_update'])
            ->where(function (Builder $inner) {
                $inner->whereNull('api_data->source')
                    ->orWhere('api_data->source', '!=', 'status_poll');
            });
    }
}
