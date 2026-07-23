<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Services\Orders\OrderDeliverySettlement;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Services\Reseller\ResellerCommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderDeliveryReturnService
{
    public function __construct(
        private readonly OrderStatusService $statusService,
        private readonly CourierBalanceService $courierBalances,
        private readonly OrderStockService $stock,
        private readonly ResellerCommissionService $resellerCommissions,
        private readonly OrderDeliverySettlement $deliverySettlement,
        private readonly OrderPaymentSync $paymentSync,
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
                actor: $changedBy ? \App\Models\User::query()->find($changedBy) : auth()->user(),
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

        return DB::transaction(function () use ($order, $changedBy) {
            $order->load('items', 'courier');

            foreach ($order->items as $item) {
                $qty = (int) $item->quantity;
                $item->update([
                    'returned_quantity' => $qty,
                    'to_be_returned' => $qty > 0,
                    'return_received' => false,
                ]);
            }

            if ($order->courier) {
                $this->courierBalances->reverseDispatchCredit($order->courier, $order, $changedBy);
            }

            $updated = $this->statusService->update(
                $order,
                'returned',
                'Cancel and Return (no delivery charge).',
                $changedBy,
                [
                    'has_return' => true,
                ],
            );

            $this->paymentSync->sync($updated->fresh());

            $this->resellerCommissions->reverseForOrder($updated->fresh(['items']));

            return $updated;
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

            if ($order->courier) {
                $this->courierBalances->settleAfterPartialReturn($order->courier, $order, (int) round($collectedTk), $changedBy);
            }

            $this->deliverySettlement->recordCollection(
                order: $order,
                amount: $collectedTk,
                actor: $changedBy ? \App\Models\User::query()->find($changedBy) : auth()->user(),
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

            return $order->refresh();
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

        return $order->refresh();
    }

    private function assertDispatched(Order $order): void
    {
        if ($order->status !== 'dispatched') {
            throw new RuntimeException('Only dispatched orders can be settled this way.');
        }
    }
}
