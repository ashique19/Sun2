<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAdjustmentSync;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Services\Reseller\ResellerCommissionService;
use App\Support\AdminOrderSegment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderDeliveryReturnService
{
    public const PARTIAL_RETURN_WRITEOFF_SOURCE = 'partial_return_writeoff';

    public function __construct(
        private readonly OrderStatusService $statusService,
        private readonly CourierBalanceService $courierBalances,
        private readonly OrderStockService $stock,
        private readonly ResellerCommissionService $resellerCommissions,
        private readonly OrderDeliverySettlement $deliverySettlement,
        private readonly OrderPaymentSync $paymentSync,
        private readonly OrderCourierChargeSync $courierChargeSync,
        private readonly OrderAdjustmentSync $adjustmentSync,
    ) {}

    public function markDelivered(Order $order, ?float $collectedAmount = null, ?int $changedBy = null): Order
    {
        $this->assertDispatched($order);

        return DB::transaction(function () use ($order, $collectedAmount, $changedBy) {
            $order->refresh();
            $collected = $collectedAmount ?? (float) $order->due_amount;

            $this->deliverySettlement->recordCollection(
                order: $order,
                amount: $collected,
                actor: $changedBy ? User::query()->find($changedBy) : auth()->user(),
                meta: ['source' => 'admin_deliver'],
            );

            $updated = $this->statusService->update(
                $order->fresh(),
                'delivered',
                'Marked delivered.',
                $changedBy,
                [
                    'actual_delivery_date' => $order->actual_delivery_date ?? now(),
                ],
            );

            $this->resellerCommissions->creditOnDelivered($updated->fresh(['items']));

            return $updated;
        });
    }

    /**
     * Full cancel & return without delivery charge (C/R).
     */
    public function cancelAndReturn(Order $order, ?int $changedBy = null): Order
    {
        $this->assertDispatched($order);

        return $this->settleCourierCancelOrReturn(
            order: $order,
            status: 'returned',
            note: 'Cancel and Return (no delivery charge).',
            changedBy: $changedBy,
            applyCourierFeeDebit: false,
        );
    }

    /**
     * Settle a courier-reported cancel/return (or admin C/R).
     *
     * - Marks all lines return-pending and sets H/R
     * - Reverses dispatch COD credit on the courier book
     * - Optionally debits courier_charge (collected = 0 ⇒ COD fee = 0; courier still charges)
     * - Syncs payments with zero new collection
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    public function settleCourierCancelOrReturn(
        Order $order,
        string $status,
        string $note,
        ?int $changedBy = null,
        bool $applyCourierFeeDebit = true,
        array $extraAttributes = [],
    ): Order {
        if (! in_array($status, ['cancelled', 'returned'], true)) {
            throw new RuntimeException('Terminal courier settle status must be cancelled or returned.');
        }

        return DB::transaction(function () use ($order, $status, $note, $changedBy, $applyCourierFeeDebit, $extraAttributes) {
            $order->refresh()->load('items', 'courier');

            $this->applyCourierReturnedLines($order);
            $order->refresh()->load('courier');

            if ($order->courier) {
                $this->courierBalances->reverseDispatchCredit($order->courier, $order, $changedBy);

                if ($applyCourierFeeDebit) {
                    $fee = (int) round(max(0.0, (float) $order->courier_charge));

                    if ($fee <= 0) {
                        $fee = (int) round($this->courierChargeSync->suggestedConfirmAmount($order));

                        if ($fee > 0) {
                            $this->courierChargeSync->set(
                                order: $order,
                                amount: (float) $fee,
                                phase: 'cancelled',
                                actor: $changedBy ? User::query()->find($changedBy) : null,
                                meta: ['source' => 'cancel_fee_fallback'],
                            );
                            $order->refresh();
                        }
                    }

                    if ($fee > 0) {
                        $this->courierBalances->debitCancelFee($order->courier, $order, $fee, $changedBy);
                    }
                }
            }

            $updated = $this->statusService->update(
                $order->fresh(),
                $status,
                $note,
                $changedBy,
                array_merge(['has_return' => true], $extraAttributes),
            );

            $this->deliverySettlement->recordCollection(
                order: $updated->fresh(),
                amount: 0.0,
                actor: $changedBy ? User::query()->find($changedBy) : null,
                meta: ['source' => 'courier_cancel_or_return'],
            );

            $this->paymentSync->sync($updated->fresh());
            $this->resellerCommissions->reverseForOrder($updated->fresh(['items']));
            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $updated->fresh();
        });
    }

    /**
     * @param  array<int, int>  $returnedQtyByItemId  order_product id => returned qty
     */
    public function partialReturn(Order $order, array $returnedQtyByItemId, float $collectedTk, ?int $changedBy = null): Order
    {
        $this->assertDispatched($order);

        if ($collectedTk < 0) {
            throw ValidationException::withMessages([
                'partialCollectedTk' => 'Collected amount cannot be negative.',
            ]);
        }

        return DB::transaction(function () use ($order, $returnedQtyByItemId, $collectedTk, $changedBy) {
            $order->load('items', 'courier');

            $anyReturned = false;
            $allReturned = true;

            foreach ($order->items as $item) {
                $ordered = (int) $item->quantity;
                $returned = (int) ($returnedQtyByItemId[$item->id] ?? 0);

                if ($returned < 0 || $returned > $ordered) {
                    throw ValidationException::withMessages([
                        'partialReturns.'.$item->id => 'Returned qty for “'.$item->name.'” must be between 0 and '.$ordered.'.',
                    ]);
                }

                if ($returned > 0) {
                    $anyReturned = true;
                }

                if ($returned < $ordered) {
                    $allReturned = false;
                }

                $item->update([
                    'returned_quantity' => $returned,
                    'to_be_returned' => $returned > 0,
                    'return_received' => false,
                ]);
            }

            if (! $anyReturned) {
                throw ValidationException::withMessages([
                    'partialReturns' => 'Enter at least one returned quantity.',
                ]);
            }

            // All products returned → cancelled; some kept → delivered.
            $status = $allReturned ? 'cancelled' : 'delivered';
            $note = $allReturned
                ? 'Partial return: all products returned. Collected ৳'.number_format($collectedTk, 0).'.'
                : 'Partial return: some products kept. Collected ৳'.number_format($collectedTk, 0).'.';

            $actor = $changedBy ? User::query()->find($changedBy) : auth()->user();

            // Write off returned merchandise (and delivery when nothing was kept) so the
            // bill matches what the rider should have collected — otherwise residual due
            // looks like a wrong "recorded" COD (e.g. 2320 − 1220 → 1100).
            $this->applyPartialReturnWriteOff($order->fresh(['items', 'adjustments']), $allReturned, $actor);

            // $collectedTk is what the rider collected from the customer (gross).
            // Do not subtract courier_charge here — that fee is applied in receivable math only.
            $order = $order->fresh(['courier']);
            if ($order->courier) {
                $this->courierBalances->settleAfterPartialReturn($order->courier, $order, (int) round($collectedTk), $changedBy);
            }

            $this->deliverySettlement->recordCollection(
                order: $order->fresh(),
                amount: $collectedTk,
                actor: $actor,
                meta: ['source' => 'admin_partial_return'],
            );

            $extras = [
                'has_return' => true,
            ];

            if ($status === 'delivered') {
                $extras['actual_delivery_date'] = $order->actual_delivery_date ?? now();
            }

            return $this->statusService->update($order->fresh(), $status, $note, $changedBy, $extras);
        });
    }

    public function markReturnReceived(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order->load('items');

            foreach ($order->items as $item) {
                $returned = (int) $item->returned_quantity;

                if ($returned <= 0 || $item->return_received) {
                    continue;
                }

                if ($item->product_id) {
                    $this->stock->release((int) $item->product_id, $returned);
                }

                $item->update([
                    'return_received' => true,
                    'to_be_returned' => true,
                ]);
            }

            // Receiving the return completes the H/R workflow — leave Return Pending.
            $this->setHasReturn($order->fresh(), false);

            return $order->fresh();
        });
    }

    public function undoReturnReceived(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order->load('items');

            foreach ($order->items as $item) {
                if (! $item->return_received) {
                    continue;
                }

                $returned = (int) $item->returned_quantity;

                if ($item->product_id && $returned > 0) {
                    $this->stock->reserve((int) $item->product_id, $returned);
                }

                $item->update([
                    'return_received' => false,
                    'to_be_returned' => $returned > 0,
                ]);
            }

            return $order->refresh();
        });
    }

    public function setHasReturn(Order $order, bool $hasReturn): Order
    {
        $order->update(['has_return' => $hasReturn]);
        Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

        return $order->refresh();
    }

    /**
     * Courier reported a full return: every line is return-pending and H/R is on.
     * Does not change order status — callers update status separately.
     */
    public function applyCourierReturnedLines(Order $order): Order
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $qty = (int) $item->quantity;

            $item->update([
                'returned_quantity' => $qty,
                'to_be_returned' => $qty > 0,
                'return_received' => false,
            ]);
        }

        if (! $order->has_return) {
            $order->update(['has_return' => true]);
            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);
        }

        return $order->refresh();
    }

    /**
     * Reduce the customer bill for returned merchandise (and delivery when nothing was kept).
     */
    private function applyPartialReturnWriteOff(Order $order, bool $allReturned, ?User $actor): void
    {
        $returnedMerchandise = 0.0;

        foreach ($order->items as $item) {
            $returnedMerchandise += (int) $item->returned_quantity * (float) $item->price;
        }

        $writeOff = $returnedMerchandise;

        if ($allReturned) {
            $writeOff += max(0.0, (float) $order->delivery_charge);
        }

        if ($writeOff <= 0) {
            return;
        }

        $this->adjustmentSync->applyPartialReturnWriteOff($order, $writeOff, $actor);
    }

    private function assertDispatched(Order $order): void
    {
        if ($order->status !== 'dispatched') {
            throw new RuntimeException('Only dispatched orders can be settled this way.');
        }
    }
}
