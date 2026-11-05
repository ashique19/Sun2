<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\Order;
use App\Services\Channels\ChannelOrderDraftCatcher;
use App\Support\Bangla;
use Illuminate\Support\Collection;

class InboxRecentOrders
{
    public const LOOKBACK_DAYS = 7;

    public const MAX_ORDERS = 12;

    /**
     * @return Collection<int, Order>
     */
    public function recent(ChannelConversation $conversation, int $days = self::LOOKBACK_DAYS): Collection
    {
        $since = now()->subDays(max(1, $days));
        $phones = $this->phones($conversation);

        return Order::query()
            ->where('status', '!=', Order::STATUS_DRAFT)
            ->where(function ($window) use ($since) {
                $window->where('placed_at', '>=', $since)
                    ->orWhere(function ($q) use ($since) {
                        $q->whereNull('placed_at')->where('created_at', '>=', $since);
                    });
            })
            ->where(function ($match) use ($conversation, $phones) {
                $match->where('channel_conversation_id', $conversation->id);

                if ($conversation->user_id) {
                    $match->orWhere('user_id', $conversation->user_id);
                }

                foreach ($phones as $phone) {
                    $match->orWhere(function ($q) use ($phone) {
                        $q->matchingPhone($phone);
                    });
                }
            })
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ORDERS)
            ->get(['id', 'order_number', 'status', 'placed_at']);
    }

    /**
     * Open New (including confirmed) or Dispatched orders for this customer.
     *
     * @param  list<string>  $extraPhones
     * @return Collection<int, Order>
     */
    public function blockingOpenOrders(ChannelConversation $conversation, array $extraPhones = []): Collection
    {
        $phones = array_values(array_unique(array_filter(
            array_merge($this->phones($conversation), array_map(
                static fn (mixed $phone): string => trim((string) $phone),
                $extraPhones,
            )),
            static fn (string $phone): bool => $phone !== '',
        )));

        return Order::query()
            ->whereIn('status', ChannelOrderDraftCatcher::BLOCKING_STATUSES)
            ->where(function ($match) use ($conversation, $phones) {
                $match->where('channel_conversation_id', $conversation->id);

                if ($conversation->user_id) {
                    $match->orWhere('user_id', $conversation->user_id);
                }

                foreach ($phones as $phone) {
                    $match->orWhere(function ($q) use ($phone) {
                        $q->matchingPhone($phone);
                    });
                }
            })
            ->latest('id')
            ->limit(5)
            ->get(['id', 'order_number', 'status', 'phone']);
    }

    public function findForConversation(ChannelConversation $conversation, int $orderId): ?Order
    {
        if ($orderId <= 0) {
            return null;
        }

        if (! $this->recent($conversation)->contains(fn (Order $order): bool => (int) $order->id === $orderId)) {
            return null;
        }

        return Order::query()->with('items')->find($orderId);
    }

    public function confirmMessage(Order $order): string
    {
        $order->loadMissing('items');

        $eta = $this->isEightyTakaDeliveryArea($order)
            ? '৩ দিনের মাঝে পাবেন'
            : '৩-৫ দিনের মাঝে পাবেন';

        $productTerms = [];
        foreach ($order->items as $item) {
            $quantity = max(1, (int) $item->quantity);
            $unit = (float) $item->price;
            for ($i = 0; $i < min($quantity, 20); $i++) {
                $productTerms[] = Bangla::money($unit);
            }
        }

        $delivery = (float) $order->delivery_charge;
        $due = (float) ($order->due_amount ?? 0);
        if ($due < 0.01) {
            $due = (float) ($order->total ?? 0);
        }

        $billParts = $productTerms;
        $billParts[] = Bangla::money($delivery).' টাকা ডেলিভারি চার্জ';

        $bill = 'বিল: '.implode(' + ', $billParts).' = '.Bangla::money($due).' টাকা';

        return "ধন্যবাদ।\n{$eta}\n{$bill}";
    }

    /**
     * @return list<string>
     */
    private function phones(ChannelConversation $conversation): array
    {
        $phones = [
            trim((string) ($conversation->customer_phone ?? '')),
            trim((string) ($conversation->draftOrder?->phone ?? '')),
            trim((string) ($conversation->user?->phone ?? '')),
        ];

        return array_values(array_unique(array_filter($phones, fn (string $phone) => $phone !== '')));
    }

    private function isEightyTakaDeliveryArea(Order $order): bool
    {
        $eighty = (int) config('checkout.dhaka_city_delivery_upto_5', 80);

        if ((int) round((float) $order->delivery_charge) === $eighty) {
            return true;
        }

        $areaName = trim((string) ($order->area ?? ''));
        if ($areaName === '') {
            return false;
        }

        return Area::query()
            ->where('delivery_charge_upto_5', $eighty)
            ->where(function ($q) use ($areaName) {
                $q->where('name', $areaName)
                    ->orWhere('police_station', $areaName);
            })
            ->exists();
    }
}
