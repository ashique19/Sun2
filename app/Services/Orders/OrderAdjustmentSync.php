<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Synchronises order_adjustment lines and keeps orders scalar caches consistent.
 *
 * Phase-2 dual-write: every path that changes adjustment lines MUST go through this
 * service so that orders.charge / discount / coupon_id / total / due_amount stay in sync.
 */
class OrderAdjustmentSync
{
    public function __construct(
        private OrderTotalCalculator $calculator,
        private OrderAdjustmentAuditor $auditor,
        private OrderPaymentSync $paymentSync,
    ) {}

    /**
     * Atomically replace all adjustment lines on an order.
     *
     * @param  list<array{
     *     type: string,
     *     label: string,
     *     amount: float|int,
     *     coupon_id?: int|null,
     *     source?: string,
     *     sort_order?: int,
     *     meta?: array|null,
     * }>  $lines  Full desired set; replaces existing lines.
     */
    public function replaceAdjustments(Order $order, array $lines, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $lines, $actor) {
            $order->load(['items', 'adjustments']);

            // Snapshot before state for audit
            $beforeSnapshot = $this->auditor->orderSnapshot($order);

            // Delete existing adjustment lines and log each deletion
            foreach ($order->adjustments as $existing) {
                $this->auditor->log($order, $existing, array_merge($beforeSnapshot, [
                    'action'        => 'deleted',
                    'amount_before' => (float) $existing->amount,
                    'meta_before'   => $existing->meta,
                    'note'          => 'Removed as part of adjustment set replacement.',
                ]), $actor);
                $existing->delete();
            }

            // Insert new lines
            $newAdjustments = new Collection;
            foreach ($lines as $i => $line) {
                $this->validateLine($line);

                $adj = OrderAdjustment::query()->create([
                    'order_id'   => $order->id,
                    'type'       => $line['type'],
                    'label'      => $line['label'],
                    'amount'     => (float) $line['amount'],
                    'coupon_id'  => $line['type'] === 'coupon' ? ($line['coupon_id'] ?? null) : null,
                    'source'     => $line['source'] ?? 'admin',
                    'sort_order' => $line['sort_order'] ?? ($i * 10),
                    'meta'       => $line['meta'] ?? null,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);

                $newAdjustments->push($adj);
            }

            // Recompute and persist order scalar caches
            $this->syncOrderScalars($order, $newAdjustments, $actor, $beforeSnapshot);
        });
    }

    /**
     * If no adjustment rows exist for this order, create them from scalar charge/discount/coupon_id.
     * Safe to call on every legacy save path — no-ops when lines already exist.
     */
    public function materializeFromScalars(Order $order): void
    {
        if ($order->adjustments()->exists()) {
            return;
        }

        $lines = [];

        if ((float) $order->charge > 0) {
            $lines[] = [
                'type'       => 'charge',
                'label'      => 'Charge',
                'amount'     => (float) $order->charge,
                'source'     => 'system',
                'sort_order' => 10,
            ];
        }

        if ((float) $order->discount > 0) {
            if ($order->coupon_id) {
                $order->loadMissing('coupon');
                $lines[] = [
                    'type'       => 'coupon',
                    'label'      => $order->coupon?->code ?? 'Coupon',
                    'amount'     => (float) $order->discount,
                    'coupon_id'  => $order->coupon_id,
                    'source'     => 'system',
                    'sort_order' => 20,
                ];
            } else {
                $lines[] = [
                    'type'       => 'discount',
                    'label'      => 'Discount',
                    'amount'     => (float) $order->discount,
                    'source'     => 'system',
                    'sort_order' => 20,
                ];
            }
        }

        if ($lines !== []) {
            $this->replaceAdjustments($order, $lines, actor: null);
        }
    }

    /**
     * Recompute orders.charge / discount / coupon_id / total from the given adjustment collection,
     * then persist. Also triggers payment sync to keep paid/due/status consistent.
     */
    private function syncOrderScalars(
        Order $order,
        Collection $adjustments,
        ?User $actor,
        array $beforeSnapshot,
    ): void {
        $charges = 0.0;
        $discounts = 0.0;
        $firstCouponId = null;

        foreach ($adjustments as $adj) {
            if ($adj->type === 'charge') {
                $charges += (float) $adj->amount;
            } else {
                $discounts += (float) $adj->amount;
                if ($adj->type === 'coupon' && $firstCouponId === null) {
                    $firstCouponId = $adj->coupon_id;
                }
            }
        }

        $totals = $this->calculator->calculate(
            subtotal: (float) $order->subtotal,
            deliveryCharge: (float) $order->delivery_charge,
            courierCharge: (float) $order->courier_charge,
            adjustments: $adjustments,
            items: $order->relationLoaded('items') ? $order->items : [],
        );

        $order->charge = $charges;
        $order->discount = $discounts;
        $order->coupon_id = $firstCouponId;
        $order->total = $totals->total;
        $order->save();

        // Log the batch replace summary with before/after order totals
        $this->auditor->log($order, null, array_merge($beforeSnapshot, $this->auditor->orderSnapshotAfter($order), [
            'action' => 'replaced_set',
            'note'   => 'Adjustment set replaced; order scalars synced.',
        ]), $actor);

        // Recompute payment caches without wiping paid amounts
        $this->paymentSync->sync($order);
    }

    /**
     * @param  array{type:string,amount:float|int,...}  $line
     */
    private function validateLine(array $line): void
    {
        $type = $line['type'] ?? '';

        if (! in_array($type, ['charge', 'discount', 'coupon'], true)) {
            throw new \InvalidArgumentException("Invalid adjustment type: '{$type}'.");
        }

        $amount = (float) ($line['amount'] ?? -1);

        if ($amount < 0) {
            throw new \InvalidArgumentException('Adjustment amount must be >= 0. Use a discount line instead of a negative charge.');
        }
    }
}
