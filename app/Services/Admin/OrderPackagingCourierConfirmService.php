<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPackagingCost;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atomically apply packaging cost + confirm courier charge for a dispatched order.
 */
class OrderPackagingCourierConfirmService
{
    public function __construct(
        private readonly OrderPackagingCost $packagingCost,
        private readonly OrderCourierChargeSync $courierChargeSync,
    ) {}

    public function confirm(
        Order $order,
        float $packagingAmount,
        float $courierAmount,
        ?User $actor = null,
    ): Order {
        return DB::transaction(function () use ($order, $packagingAmount, $courierAmount, $actor) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== 'dispatched') {
                throw new RuntimeException('Only dispatched orders can confirm courier charge.');
            }

            if ($order->courier_charge_confirmed_at !== null) {
                throw new RuntimeException('Courier charge is already confirmed for this order.');
            }

            $this->packagingCost->apply($order, $packagingAmount);
            $this->courierChargeSync->confirm(
                order: $order->fresh(),
                amount: $courierAmount,
                actor: $actor,
            );

            return $order->fresh();
        });
    }
}
