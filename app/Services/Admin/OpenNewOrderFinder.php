<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

class OpenNewOrderFinder
{
    /**
     * Open New orders for this phone (not drafts, not already dispatched).
     *
     * @return Collection<int, Order>
     */
    public function forPhone(string $phone, ?int $exceptOrderId = null): Collection
    {
        $display = PhoneNumber::display($phone);

        if ($display === '' || (! PhoneNumber::isValidDisplayMobile($display) && ! PhoneNumber::isValidBangladeshMobile($phone))) {
            return collect();
        }

        return Order::query()
            ->matchingPhone($display)
            ->where('status', Order::STATUS_NEW)
            ->when($exceptOrderId, fn ($query) => $query->whereKeyNot($exceptOrderId))
            ->with(['items:id,order_id,name,quantity'])
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * @param  iterable<Order>  $orders
     * @return array<int, list<array{id:int,order_number:string,total:float,item_count:int,name:string}>>
     */
    public function mapForOrders(iterable $orders): array
    {
        $map = [];

        foreach ($orders as $order) {
            $map[(int) $order->id] = $this->summaries($this->forPhone((string) $order->phone, (int) $order->id));
        }

        return $map;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<array{id:int,order_number:string,total:float,item_count:int,name:string}>
     */
    public function summaries(Collection $orders): array
    {
        return $orders
            ->map(fn (Order $order) => $this->summarize($order))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,order_number:string,total:float,item_count:int,name:string}
     */
    public function summarize(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'total' => (float) $order->total,
            'item_count' => (int) $order->items->sum('quantity'),
            'name' => (string) $order->name,
        ];
    }

    public function findMatchingNew(int $targetId, string $phone, ?int $exceptOrderId = null): ?Order
    {
        return $this->forPhone($phone, $exceptOrderId)->firstWhere('id', $targetId);
    }
}
