<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Services\Orders\OrderCourierChargeSync;
use App\Services\Orders\OrderPackagingCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderPackagingCourierRepairService
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private OrderPackagingCost $packaging,
        private OrderCourierChargeSync $courierCharges,
    ) {}

    public function eligibleOrderCount(): int
    {
        return (int) Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where(function ($query): void {
                $query->where('packaging_cost', '<', 0.01)
                    ->orWhere('courier_charge', '<', 0.01);
            })
            ->count();
    }

    /**
     * @return array{
     *     scanned: int,
     *     fixed_orders: int,
     *     packaging_fixed: int,
     *     courier_fixed: int,
     *     next_after_id: int,
     *     done: bool,
     *     sample_order_numbers: list<string>
     * }
     */
    public function repairNextBatch(int $afterId = 0, int $limit = self::BATCH_SIZE): array
    {
        $limit = max(1, min(self::BATCH_SIZE, $limit));

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->with(['items.product.category', 'courier:id,name,slug,charge,osd_charge,cod_percentage'])
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where('id', '>', $afterId)
            ->where(function ($query): void {
                $query->where('packaging_cost', '<', 0.01)
                    ->orWhere('courier_charge', '<', 0.01);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $fixedOrders = 0;
        $packagingFixed = 0;
        $courierFixed = 0;
        $samples = [];

        foreach ($orders as $order) {
            $result = DB::transaction(fn (): array => $this->repairOrder($order));

            if ($result['changed']) {
                $fixedOrders++;
                $packagingFixed += $result['packaging_fixed'] ? 1 : 0;
                $courierFixed += $result['courier_fixed'] ? 1 : 0;
                if (count($samples) < 8) {
                    $samples[] = (string) $order->order_number;
                }
            }
        }

        $lastId = $orders->last()?->id ?? $afterId;

        return [
            'scanned' => $orders->count(),
            'fixed_orders' => $fixedOrders,
            'packaging_fixed' => $packagingFixed,
            'courier_fixed' => $courierFixed,
            'next_after_id' => (int) $lastId,
            'done' => $orders->count() < $limit,
            'sample_order_numbers' => $samples,
        ];
    }

    /**
     * Fill ৳0 packaging / courier from the current rate cards when possible.
     *
     * @return array{changed: bool, packaging_fixed: bool, courier_fixed: bool}
     */
    public function repairOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load(['items.product.category', 'courier']);
            $packagingFixed = false;
            $courierFixed = false;

            if (round((float) ($order->packaging_cost ?? 0), 2) < 0.01) {
                $estimate = $this->packaging->estimateFor($order);

                if ($estimate >= 0.01) {
                    $this->packaging->apply($order, $estimate);
                    $packagingFixed = true;
                }
            }

            if (round((float) ($order->courier_charge ?? 0), 2) < 0.01) {
                $fee = $this->courierCharges->estimateMerchantDeliveryFee($order, $order->courier);

                if ($fee >= 0.01) {
                    $this->courierCharges->set(
                        $order->fresh(),
                        $fee,
                        'manual',
                        null,
                        [
                            'source' => 'packaging_courier_repair',
                            'rule' => 'piece_based_rate_card',
                        ],
                    );
                    $courierFixed = true;
                }
            }

            return [
                'changed' => $packagingFixed || $courierFixed,
                'packaging_fixed' => $packagingFixed,
                'courier_fixed' => $courierFixed,
            ];
        });
    }
}
