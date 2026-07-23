<?php

namespace App\Services\Admin;

use App\Models\Courier;
use App\Models\CourierData;
use App\Models\Order;
use App\Services\Couriers\CarryBeeApiClient;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\PathaoApiClient;
use App\Services\Couriers\RedxApiClient;
use App\Services\Couriers\SteadfastApiClient;
use App\Services\Orders\OrderCourierChargeSync;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderDispatchService
{
    public function __construct(
        private readonly SteadfastApiClient $steadfast,
        private readonly PathaoApiClient $pathao,
        private readonly RedxApiClient $redx,
        private readonly CarryBeeApiClient $carrybee,
        private readonly CourierApiRegistry $courierRegistry,
        private readonly OrderStatusService $statusService,
        private readonly CourierBalanceService $courierBalances,
        private readonly OrderCourierChargeSync $courierChargeSync,
    ) {}

    public function dispatchViaApi(Order $order, string $slug, ?int $changedBy = null, bool $markDispatched = true): Order
    {
        $slug = strtolower(trim($slug));

        if (! $this->courierRegistry->isConfigured($slug)) {
            throw new RuntimeException(ucfirst($slug).' API is not configured.');
        }

        $courier = Courier::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $courier) {
            throw new RuntimeException(ucfirst($slug).' courier is not active in the database.');
        }

        [$response, $trackingCode] = match ($slug) {
            'steadfast' => $this->dispatchSteadfastPayload($order),
            'pathao' => $this->dispatchPathaoPayload($order),
            'redx' => $this->dispatchRedxPayload($order),
            'carrybee' => $this->dispatchCarrybeePayload($order),
            default => throw new RuntimeException('Unsupported courier API: '.$slug),
        };

        return $this->finalizeApiDispatch(
            $order,
            $courier,
            $response,
            $trackingCode,
            $courier->name,
            $changedBy,
            $markDispatched,
        );
    }

    public function dispatchToSteadfast(Order $order, ?int $changedBy = null): Order
    {
        return $this->dispatchViaApi($order, 'steadfast', $changedBy);
    }

    public function assignManual(Order $order, int $courierId, string $tracker, ?int $changedBy = null): Order
    {
        $courier = Courier::query()->findOrFail($courierId);

        if (trim($tracker) === '') {
            throw new RuntimeException('Tracking code is required.');
        }

        return DB::transaction(function () use ($order, $courier, $tracker, $changedBy) {
            $previousTracker = filled($order->courier_tracker) ? (string) $order->courier_tracker : null;
            $tracker = trim($tracker);

            $note = $previousTracker && $previousTracker !== $tracker
                ? 'Tracking replaced via '.$courier->name.'. '.$previousTracker.' → '.$tracker
                : 'Dispatched via '.$courier->name.'. Tracking: '.$tracker;

            $order = $this->statusService->update(
                $order,
                'dispatched',
                $note,
                $changedBy,
                [
                    'courier_id' => $courier->id,
                    'courier_tracker' => $tracker,
                    'dispatch_date' => $order->dispatch_date ?? now(),
                ],
            );

            $this->courierBalances->creditOnDispatch($courier, $order, $changedBy);

            $this->syncCourierChargeOnDispatch($order->fresh(), $courier);

            return $order;
        });
    }

    /**
     * Mark an order as dispatched for a courier (no API call). Credits book balance.
     */
    public function markAsDispatched(Order $order, int $courierId, ?int $changedBy = null): Order
    {
        if (! in_array($order->status, ['new', 'confirmed'], true)) {
            throw new RuntimeException('Only new or confirmed orders can be marked dispatched.');
        }

        $courier = Courier::query()
            ->whereKey($courierId)
            ->where('is_active', true)
            ->first();

        if (! $courier) {
            throw new RuntimeException('Selected courier is not active.');
        }

        return DB::transaction(function () use ($order, $courier, $changedBy) {
            $note = 'Marked dispatched via '.$courier->name.'.';
            if (filled($order->courier_tracker)) {
                $note .= ' Tracking: '.$order->courier_tracker;
            }

            $order = $this->statusService->update(
                $order,
                'dispatched',
                $note,
                $changedBy,
                [
                    'courier_id' => $courier->id,
                    'dispatch_date' => $order->dispatch_date ?? now(),
                ],
            );

            $this->courierBalances->creditOnDispatch($courier, $order, $changedBy);

            $this->syncCourierChargeOnDispatch($order->fresh(), $courier);

            return $order;
        });
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchSteadfastPayload(Order $order): array
    {
        $payload = [
            'invoice' => (string) $order->order_number,
            'recipient_name' => $order->name,
            'recipient_phone' => PhoneNumber::display($order->phone),
            'recipient_address' => $this->formatAddress($order),
            'cod_amount' => $order->collectableAmount(),
            'note' => $order->courier_note ?: null,
            'recipient_email' => $order->email,
            'item_description' => $this->itemSummary($order),
            'delivery_type' => 0,
        ];

        $response = $this->steadfast->createOrder(array_filter($payload, fn ($v) => $v !== null && $v !== ''));
        $consignment = $response['consignment'] ?? $response['data']['consignment'] ?? null;

        if (! is_array($consignment)) {
            throw new RuntimeException('Steadfast did not return consignment data.');
        }

        $trackingCode = (string) ($consignment['tracking_code'] ?? $consignment['tracking_id'] ?? '');

        if ($trackingCode === '') {
            throw new RuntimeException('Steadfast did not return a tracking code.');
        }

        // Keep tracking_code for status APIs; consignment_id is the parcel Id shown in Steadfast UI / print.
        return [$response, $trackingCode];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractConsignmentId(array $response): ?string
    {
        $id = data_get($response, 'consignment.consignment_id')
            ?? data_get($response, 'consignment.id')
            ?? data_get($response, 'data.consignment.consignment_id')
            ?? data_get($response, 'data.consignment.id')
            ?? data_get($response, 'data.consignment_id')
            ?? data_get($response, 'data.data.consignment_id')
            ?? data_get($response, 'data.order.consignment_id');

        return filled($id) ? (string) $id : null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchPathaoPayload(Order $order): array
    {
        $response = $this->pathao->createOrder($order);
        $trackingCode = (string) data_get($response, 'data.consignment_id', data_get($response, 'data.data.consignment_id', ''));

        if ($trackingCode === '') {
            throw new RuntimeException('Pathao did not return a consignment ID.');
        }

        return [$response, $trackingCode];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchRedxPayload(Order $order): array
    {
        $response = $this->redx->createParcel($order);
        $trackingCode = (string) data_get($response, 'tracking_id', data_get($response, 'data.tracking_id', ''));

        if ($trackingCode === '') {
            throw new RuntimeException('RedX did not return a tracking ID.');
        }

        return [$response, $trackingCode];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchCarrybeePayload(Order $order): array
    {
        $response = $this->carrybee->createOrder($order);
        $trackingCode = (string) data_get($response, 'data.order.consignment_id', data_get($response, 'data.consignment_id', ''));

        if ($trackingCode === '') {
            throw new RuntimeException('CarryBee did not return a consignment ID.');
        }

        return [$response, $trackingCode];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function finalizeApiDispatch(
        Order $order,
        Courier $courier,
        array $response,
        string $trackingCode,
        string $courierName,
        ?int $changedBy,
        bool $markDispatched = true,
    ): Order {
        return DB::transaction(function () use ($order, $courier, $response, $trackingCode, $courierName, $changedBy, $markDispatched) {
            $courierData = CourierData::query()->create([
                'order_id' => $order->id,
                'courier_id' => $courier->id,
                'api_data' => $response,
                'created_at' => now(),
            ]);

            $consignmentId = $this->extractConsignmentId($response);
            $previousTracker = filled($order->courier_tracker) ? (string) $order->courier_tracker : null;

            // Always overwrite tracker / consignment id — a new send replaces the previous one.
            $courierFields = [
                'courier_id' => $courier->id,
                'courier_tracker' => $trackingCode,
                'courier_consignment_id' => $consignmentId,
            ];

            $note = $previousTracker && $previousTracker !== $trackingCode
                ? 'Tracking replaced via '.$courierName.'. '.$previousTracker.' → '.$trackingCode
                    .($consignmentId ? ' Parcel ID: '.$consignmentId : '')
                : 'Dispatched via '.$courierName.'. Tracking: '.$trackingCode
                    .($consignmentId ? ' Parcel ID: '.$consignmentId : '');

            if ($markDispatched) {
                $order = $this->statusService->update(
                    $order,
                    'dispatched',
                    $note,
                    $changedBy,
                    array_merge($courierFields, [
                        'dispatch_date' => $order->dispatch_date ?? now(),
                    ]),
                );

                $this->courierBalances->creditOnDispatch($courier, $order, $changedBy);

                $this->syncCourierChargeOnDispatch($order->fresh(), $courier, $response, (int) $courierData->id);

                return $order;
            }

            $order->update($courierFields);

            $this->syncCourierChargeOnDispatch($order->fresh(), $courier, $response, (int) $courierData->id);

            $this->statusService->record(
                $order,
                $previousTracker && $previousTracker !== $trackingCode
                    ? 'Tracking replaced via '.$courierName.' API. '.$previousTracker.' → '.$trackingCode
                        .($consignmentId ? ' Parcel ID: '.$consignmentId : '')
                    : 'Sent to '.$courierName.' via API. Tracking: '.$trackingCode
                        .($consignmentId ? ' Parcel ID: '.$consignmentId : ''),
                $changedBy,
            );

            return $order->fresh();
        });
    }

    private function formatAddress(Order $order): string
    {
        $parts = array_filter([
            $order->address,
            $order->area,
            $order->city,
            $order->state,
        ]);

        return mb_substr(implode(', ', $parts), 0, 250);
    }

    private function itemSummary(Order $order): string
    {
        $order->loadMissing('items');

        return $order->items
            ->map(fn ($item) => $item->name.' x'.$item->quantity)
            ->take(3)
            ->implode('; ');
    }

    /**
     * @param  array<string, mixed>|null  $apiResponse
     */
    private function syncCourierChargeOnDispatch(Order $order, Courier $courier, ?array $apiResponse = null, ?int $courierDataId = null): void
    {
        $fee = $apiResponse
            ? $this->courierChargeSync->parseFeeFromPayload($apiResponse)
            : null;
        $fee ??= $this->courierChargeSync->estimateFromCatalog($order, $courier);

        if ($fee <= 0) {
            return;
        }

        $this->courierChargeSync->sync(
            order: $order,
            fee: $fee,
            actor: auth()->user(),
            phase: 'dispatch',
            meta: $apiResponse ? ['source' => 'dispatch_api'] : ['source' => 'catalog_estimate'],
            courierDataId: $courierDataId,
        );
    }
}
