<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Collection;

class OrderPrintSlip
{
    /**
     * Full slip fields (admin helpers / address formatting).
     *
     * @return array{
     *     id: int,
     *     order_number: string,
     *     parcel_id: string|null,
     *     brand: string,
     *     name: string,
     *     phone: string,
     *     address: string,
     *     due_tk: int
     * }
     */
    public static function fromOrder(Order $order): array
    {
        $shippingAddress = collect([
            $order->address,
            $order->area,
            $order->city,
            $order->state,
        ])->filter(fn ($part) => filled($part))
            ->unique()
            ->implode(', ');

        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'parcel_id' => $order->printParcelId(),
            'brand' => 'Sundoritoma.com',
            'name' => (string) $order->name,
            'phone' => (string) $order->phone,
            'address' => $shippingAddress,
            'due_tk' => (int) round($order->collectableAmount()),
        ];
    }

    /**
     * Minimal thermal / OTG payload — matches admin print-selected layout.
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
            'brand' => 'Sundoritoma.com',
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
