<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Collection;

class OrderPrintSlip
{
    public const BRAND = 'SUNDORITOMA.COM';

    /**
     * Minimal thermal / OTG / browser-print payload.
     *
     * @return array{
     *     id: int,
     *     order_number: string,
     *     parcel_id: string|null,
     *     brand: string,
     *     name: string
     * }
     */
    public static function thermalFromOrder(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'parcel_id' => $order->printParcelId(),
            'brand' => self::BRAND,
            'name' => (string) $order->name,
        ];
    }

    /**
     * @param  Collection<int, int|string>  $ids  Preferred print order
     * @return list<array<string, mixed>>
     */
    public static function collectionFromIds(Collection $ids): array
    {
        $orderedIds = $ids
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($orderedIds->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', $orderedIds)
            ->get()
            ->sortBy(fn (Order $order) => $orderedIds->search($order->id))
            ->values();

        return $orders
            ->map(fn (Order $order) => self::thermalFromOrder($order))
            ->all();
    }
}
