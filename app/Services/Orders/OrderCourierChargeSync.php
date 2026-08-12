<?php

namespace App\Services\Orders;

use App\Models\Area;
use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;

/**
 * Single writer for orders.courier_charge.
 *
 * Only updates the column (and appends an audit log) when the value actually changes.
 * Never touches orders.delivery_charge — the two fields are independent.
 *
 * Phases: dispatch | webhook | tracking | delivered | cancelled | manual | confirm
 */
class OrderCourierChargeSync
{
    public function __construct(
        private OrderAdjustmentAuditor $auditor,
        private OrderEmptyProductDefaults $emptyProductDefaults = new OrderEmptyProductDefaults,
    ) {}

    /**
     * Set courier_charge when a fee is known; no-op when fee is null.
     *
     * @param  string  $phase  dispatch|webhook|tracking|delivered|cancelled|manual|confirm
     */
    public function sync(
        Order $order,
        ?float $fee,
        ?User $actor,
        string $phase,
        ?array $meta = null,
        ?int $courierDataId = null,
    ): void {
        if ($fee === null) {
            return;
        }

        $this->set($order, $fee, $phase, $actor, $meta, $courierDataId);
    }

    public function set(
        Order $order,
        float $amount,
        string $phase,
        ?User $actor = null,
        ?array $meta = null,
        ?int $courierDataId = null,
    ): void {
        $before = round((float) $order->courier_charge, 2);
        $after = round($amount, 2);

        if ($before === $after) {
            return; // No change — skip write and audit
        }

        $order->courier_charge = $after;

        // API/manual amount changes after a prior confirm need re-review.
        if ($order->courier_charge_confirmed_at !== null && $phase !== 'confirm') {
            $order->courier_charge_confirmed_at = null;
            $order->courier_charge_confirmed_by = null;
        }

        $order->save();

        $this->auditor->logField($order, [
            'field' => 'courier_charge',
            'phase' => $phase,
            'amount_before' => $before,
            'amount_after' => $after,
            'source_courier_data_id' => $courierDataId,
            'meta_after' => $meta,
            'note' => "Courier charge updated at phase '{$phase}'.",
        ], $actor);
    }

    /**
     * Persist the reviewed courier charge and mark it confirmed.
     */
    public function confirm(Order $order, float $amount, ?User $actor = null, ?string $reason = null): void
    {
        $before = round((float) $order->courier_charge, 2);
        $after = round($amount, 2);
        $meta = array_filter([
            'source' => 'manual_confirm',
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'confirmed' => true,
        ], fn ($value) => $value !== null && $value !== '');

        if ($before !== $after) {
            $order->courier_charge = $after;
            $this->auditor->logField($order, [
                'field' => 'courier_charge',
                'phase' => 'confirm',
                'amount_before' => $before,
                'amount_after' => $after,
                'meta_after' => $meta,
                'note' => 'Courier charge confirmed after manual review.',
            ], $actor);
        } else {
            $this->auditor->logField($order, [
                'field' => 'courier_charge',
                'phase' => 'confirm',
                'amount_before' => $before,
                'amount_after' => $after,
                'meta_after' => $meta,
                'note' => 'Courier charge confirmed (amount unchanged).',
            ], $actor);
        }

        $order->courier_charge_confirmed_at = now();
        $order->courier_charge_confirmed_by = $actor?->id;
        $order->save();
    }

    /**
     * Clear confirmation when an order is (re)dispatched with a fresh estimate.
     */
    public function clearConfirmation(Order $order): void
    {
        if ($order->courier_charge_confirmed_at === null && $order->courier_charge_confirmed_by === null) {
            return;
        }

        $order->courier_charge_confirmed_at = null;
        $order->courier_charge_confirmed_by = null;
        $order->save();
    }

    /**
     * Estimate courier fee from catalog rates (Dhaka vs outside).
     *
     * @deprecated Prefer estimateMerchantDeliveryFee() for piece-based rates.
     */
    public function estimateFromCatalog(Order $order, ?Courier $courier): float
    {
        return $this->estimateMerchantDeliveryFee($order, $courier);
    }

    /**
     * Merchant delivery fee (orders.courier_charge) from piece-based rate card.
     *
     * RedX: ৳50 / ৳100 first piece (Dhaka / outside), +৳10 each extra.
     * Others: ৳60 / ৳120 first piece (Dhaka / outside), +৳10 each extra.
     */
    public function estimateMerchantDeliveryFee(Order $order, ?Courier $courier = null): float
    {
        $courier ??= $order->relationLoaded('courier') ? $order->courier : $order->courier()->first();
        $courier ??= $this->resolveDefaultCourier();

        if (! $courier) {
            return 0.0;
        }

        $order->loadMissing('items');
        $qty = $this->emptyProductDefaults->sellableQuantity($order);
        $status = strtolower((string) $order->status);

        // Returned / fully-returned delivered parcels still incurred a courier trip.
        if ($qty < 1 && in_array($status, ['delivered', 'returned', 'dispatched', 'partial'], true)) {
            $qty = $this->emptyProductDefaults->shippedQuantity($order);
        }

        // Delivered/returned with no line qty still paid for a trip — bill 1 piece.
        if ($qty < 1 && in_array($status, ['delivered', 'returned'], true)) {
            $qty = 1;
        }

        if ($qty < 1) {
            return 0.0;
        }

        $isRedx = strtolower(trim((string) $courier->slug)) === 'redx';
        $isDhaka = $this->isDhakaOrder($order);

        $first = match (true) {
            $isRedx && $isDhaka => 50.0,
            $isRedx && ! $isDhaka => 100.0,
            ! $isRedx && $isDhaka => 60.0,
            default => 120.0,
        };

        return round($first + (($qty - 1) * 10.0), 2);
    }

    /**
     * Default for the confirm-courier-charges UI: what the courier charges us.
     *
     * Prefer the order's existing courier_charge (dispatch/API estimate).
     * Otherwise fall back to piece-based Dhaka/outside rates.
     *
     * Never uses areas.delivery_charge_* — that is what we charge the customer.
     */
    public function suggestedConfirmAmount(Order $order, ?Courier $courier = null): float
    {
        $existing = round((float) ($order->courier_charge ?? 0), 2);

        if ($existing > 0) {
            return $existing;
        }

        $courier ??= $order->relationLoaded('courier') ? $order->courier : $order->courier()->first();

        return round($this->estimateMerchantDeliveryFee($order, $courier), 2);
    }

    /**
     * Human label for the location used by the confirm UI (area name or Dhaka/outside).
     */
    public function areaRateLabel(Order $order): string
    {
        $area = $this->resolveArea($order);

        if ($area) {
            return (string) $area->name;
        }

        return $this->isDhakaOrder($order) ? 'Dhaka' : 'Outside Dhaka';
    }

    /**
     * Quick-pick courier charge amounts for the confirm UI, by area type.
     *
     * These are Steadfast-style merchant costs (what courier charges us), not
     * customer delivery fees from areas.delivery_charge_*.
     *
     * - Dhaka thana: 65, 75
     * - Dhaka upazila: 125
     * - Outside Dhaka: 135, 155
     *
     * @return list<int>
     */
    public function quickConfirmAmounts(Order $order): array
    {
        $area = $this->resolveArea($order);
        $unitType = strtolower(trim((string) ($area?->unit_type ?? '')));
        $isDhaka = (bool) ($area?->city?->is_dhaka) || $this->isDhakaOrder($order);

        if ($isDhaka && $unitType === 'upazila') {
            return [125];
        }

        if ($isDhaka) {
            return [65, 75];
        }

        return [135, 155];
    }

    /**
     * Resolve areas row where areas.name matches orders.area.
     * Prefer a row whose city also matches orders.city when available.
     */
    public function resolveArea(Order $order): ?Area
    {
        $areaName = trim((string) ($order->area ?? ''));

        if ($areaName === '') {
            return null;
        }

        $cityName = trim((string) ($order->city ?? ''));

        if ($cityName !== '') {
            $scoped = Area::query()
                ->with('city:id,name,is_dhaka')
                ->where('name', $areaName)
                ->whereHas('city', fn ($query) => $query->where('name', $cityName))
                ->first();

            if ($scoped) {
                return $scoped;
            }
        }

        return Area::query()
            ->with('city:id,name,is_dhaka')
            ->where('name', $areaName)
            ->first();
    }

    /**
     * Parse a courier fee from common API / webhook payload keys.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseFeeFromPayload(array $payload): ?float
    {
        foreach ([
            'delivery_fee',
            'delivery_charge',
            'courier_charge',
            'courier_fee',
            'shipping_fee',
            'shipping_charge',
            'merchant_delivery_fee',
            'fee',
        ] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                continue;
            }

            return round((float) $payload[$key], 2);
        }

        foreach (['data', 'consignment', 'order', 'parcel'] as $nested) {
            $inner = $payload[$nested] ?? null;

            if (! is_array($inner)) {
                continue;
            }

            $fee = $this->parseFeeFromPayload($inner);

            if ($fee !== null) {
                return $fee;
            }
        }

        return null;
    }

    public function isDhakaOrder(Order $order): bool
    {
        $cityName = trim((string) ($order->city ?? ''));

        if ($cityName === '') {
            return false;
        }

        $normalized = mb_strtolower($cityName);
        $dhakaCities = array_map(
            fn (string $city): string => mb_strtolower(trim($city)),
            config('checkout.dhaka_cities', ['dhaka']),
        );

        if (in_array($normalized, $dhakaCities, true)) {
            return true;
        }

        return (bool) City::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->value('is_dhaka');
    }

    /**
     * Active default courier (or first active) for rate-card estimates when the order has none.
     */
    public function resolveDefaultCourier(): ?Courier
    {
        return Courier::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first()
            ?? Courier::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
    }
}
