<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderAdjustmentLog;
use App\Models\User;

/**
 * Writes append-only audit rows into order_adjustment_logs.
 *
 * Covers:
 * - adjustment line mutations (created / updated / deleted / replaced_set / backfilled)
 * - non-adjustment money field changes (delivery_charge, courier_charge) via field= parameter
 *
 * Never updates or deletes existing log rows.
 */
class OrderAdjustmentAuditor
{
    /**
     * Log a mutation on an adjustment line.
     *
     * @param  array{
     *     action: string,
     *     amount_before?: float|null,
     *     amount_after?: float|null,
     *     meta_before?: array|null,
     *     meta_after?: array|null,
     *     note?: string|null,
     *     field?: string|null,
     *     phase?: string|null,
     *     source_courier_data_id?: int|null,
     * }  $data
     */
    public function log(
        Order $order,
        ?OrderAdjustment $adjustment,
        array $data,
        ?User $actor = null,
    ): OrderAdjustmentLog {
        return OrderAdjustmentLog::query()->create([
            'order_id'               => $order->id,
            'order_adjustment_id'    => $adjustment?->id,
            'action'                 => $data['action'],
            'type'                   => $adjustment?->type ?? null,
            'label'                  => $adjustment?->label ?? null,
            'field'                  => $data['field'] ?? null,
            'phase'                  => $data['phase'] ?? null,
            'source_courier_data_id' => $data['source_courier_data_id'] ?? null,
            'amount_before'          => $data['amount_before'] ?? null,
            'amount_after'           => $data['amount_after'] ?? null,
            'coupon_id'              => $adjustment?->coupon_id ?? null,
            'meta_before'            => $data['meta_before'] ?? null,
            'meta_after'             => $data['meta_after'] ?? null,
            'order_charge_before'    => $data['order_charge_before'] ?? null,
            'order_charge_after'     => $data['order_charge_after'] ?? null,
            'order_discount_before'  => $data['order_discount_before'] ?? null,
            'order_discount_after'   => $data['order_discount_after'] ?? null,
            'order_total_before'     => $data['order_total_before'] ?? null,
            'order_total_after'      => $data['order_total_after'] ?? null,
            'note'                   => $data['note'] ?? null,
            'actor_id'               => $actor?->id,
        ]);
    }

    /**
     * Log a non-adjustment money field change (delivery_charge or courier_charge).
     *
     * @param  array{
     *     field: string,
     *     phase?: string|null,
     *     amount_before?: float|null,
     *     amount_after?: float|null,
     *     source_courier_data_id?: int|null,
     *     note?: string|null,
     * }  $data
     */
    public function logField(
        Order $order,
        array $data,
        ?User $actor = null,
    ): OrderAdjustmentLog {
        return $this->log($order, null, array_merge($data, ['action' => 'updated']), $actor);
    }

    /** Snapshot current order charge/discount/total scalars into a data array. */
    public function orderSnapshot(Order $order): array
    {
        return [
            'order_charge_before'   => (float) $order->charge,
            'order_discount_before' => (float) $order->discount,
            'order_total_before'    => (float) $order->total,
        ];
    }

    /** Same as orderSnapshot but for "after" side (after order has been saved). */
    public function orderSnapshotAfter(Order $order): array
    {
        return [
            'order_charge_after'   => (float) $order->charge,
            'order_discount_after' => (float) $order->discount,
            'order_total_after'    => (float) $order->total,
        ];
    }
}
