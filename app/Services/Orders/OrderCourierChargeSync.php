<?php

namespace App\Services\Orders;

use App\Models\Courier;
use App\Models\Order;
use App\Models\User;

/**
 * Single writer for orders.courier_charge.
 *
 * Only updates the column (and appends an audit log) when the value actually changes.
 * Never touches orders.delivery_charge — the two fields are independent.
 *
 * Phases: dispatch | webhook | tracking | delivered | cancelled | manual
 */
class OrderCourierChargeSync
{
    public function __construct(
        private OrderAdjustmentAuditor $auditor,
    ) {}

    /**
     * Set courier_charge when a fee is known; no-op when fee is null.
     *
     * @param  string  $phase  dispatch|webhook|tracking|delivered|cancelled|manual
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
        $order->save();

        $this->auditor->logField($order, [
            'field'                  => 'courier_charge',
            'phase'                  => $phase,
            'amount_before'          => $before,
            'amount_after'           => $after,
            'source_courier_data_id' => $courierDataId,
            'meta_after'             => $meta,
            'note'                   => "Courier charge updated at phase '{$phase}'.",
        ], $actor);
    }

    /**
     * Estimate courier fee from catalog rates (Dhaka vs outside).
     */
    public function estimateFromCatalog(Order $order, ?Courier $courier): float
    {
        if (! $courier) {
            return 0.0;
        }

        return $this->isDhakaOrder($order)
            ? (float) $courier->charge
            : (float) $courier->osd_charge;
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

    private function isDhakaOrder(Order $order): bool
    {
        $city = strtolower(trim((string) ($order->city ?? '')));
        $dhakaCities = array_map('strtolower', config('checkout.dhaka_cities', ['dhaka']));

        return in_array($city, $dhakaCities, true);
    }
}
