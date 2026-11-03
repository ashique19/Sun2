<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Orders\OrderPaymentSync;
use App\Services\Orders\OrderStockService;
use App\Services\Orders\OrderTotalCalculator;
use App\Support\AdminOrderSegment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderMergeService
{
    public function __construct(
        private OrderStockService $stock,
        private OrderStatusService $statusService,
        private ChannelOrderDraftService $drafts,
        private OrderTotalCalculator $totals,
        private OrderPaymentSync $payments,
    ) {}

    /**
     * Fold an AI draft into an existing New order so staff do not confirm a duplicate.
     */
    public function mergeDraftIntoNew(Order $draft, Order $target, ?int $userId = null): Order
    {
        if (! $draft->isAiDraft()) {
            throw new InvalidArgumentException('Only AI drafts can be merged this way.');
        }

        if ($target->status !== Order::STATUS_NEW) {
            throw new InvalidArgumentException('Can only merge into a New order.');
        }

        if ((int) $draft->id === (int) $target->id) {
            throw new InvalidArgumentException('Cannot merge an order into itself.');
        }

        return DB::transaction(function () use ($draft, $target, $userId) {
            $draft->load('items');
            $lines = $draft->items
                ->map(fn (OrderProduct $item) => $this->lineFromItem($item))
                ->all();

            $merged = $this->appendLines($target, $lines, $userId, 'Merged AI draft #'.$draft->order_number.' to prevent a duplicate New order.');

            if (! $merged->channel_conversation_id && $draft->channel_conversation_id) {
                $merged->forceFill(['channel_conversation_id' => $draft->channel_conversation_id])->save();
            }

            $this->drafts->discard($draft);

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $merged->fresh(['items']);
        });
    }

    /**
     * Fold create-order form lines into an existing New order.
     *
     * @param  list<array{product_id:?int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,unit_cost?:float}>  $lines
     */
    public function mergeLinesIntoNew(Order $target, array $lines, ?int $userId = null): Order
    {
        if ($target->status !== Order::STATUS_NEW) {
            throw new InvalidArgumentException('Can only merge into a New order.');
        }

        return DB::transaction(function () use ($target, $lines, $userId) {
            $note = $lines === []
                ? 'Opened existing New order instead of creating a duplicate.'
                : 'Merged create-order items into New order to prevent a duplicate.';

            $merged = $this->appendLines($target, $lines, $userId, $note);

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $merged->fresh(['items']);
        });
    }

    /**
     * @param  list<array{product_id:?int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,unit_cost?:float,base_price?:float,commission_rate?:float,max_discount?:mixed}>  $lines
     */
    private function appendLines(Order $target, array $lines, ?int $userId, string $historyNote): Order
    {
        $target->load(['items', 'adjustments', 'courier']);

        $added = [];
        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $productId = isset($line['product_id']) ? (int) $line['product_id'] : 0;
            $productId = $productId > 0 ? $productId : null;

            if ($productId) {
                $existing = $target->items->firstWhere('product_id', $productId);
                if ($existing) {
                    $nextQty = (int) $existing->quantity + $quantity;
                    $existing->forceFill([
                        'quantity' => $nextQty,
                        'line_total' => (float) $existing->price * $nextQty,
                    ])->save();
                    $added[$productId] = ($added[$productId] ?? 0) + $quantity;

                    continue;
                }
            }

            $created = OrderProduct::query()->create([
                'order_id' => $target->id,
                'product_id' => $productId,
                'name' => (string) ($line['name'] ?? 'Item'),
                'product_image' => $line['product_image'] ?? null,
                'quantity' => $quantity,
                'base_price' => $line['base_price'] ?? $line['price'] ?? 0,
                'price' => $line['price'] ?? 0,
                'purchase_price' => $line['purchase_price'] ?? 0,
                'unit_cost' => array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null
                    ? (float) $line['unit_cost']
                    : (float) ($line['purchase_price'] ?? 0),
                'commission_rate' => $line['commission_rate'] ?? 0,
                'commission_earned' => 0,
                'max_discount' => isset($line['max_discount']) && $line['max_discount'] !== null
                    ? (float) $line['max_discount']
                    : null,
                'line_total' => $line['line_total'] ?? ((float) ($line['price'] ?? 0) * $quantity),
            ]);
            $target->items->push($created);

            if ($productId) {
                $added[$productId] = ($added[$productId] ?? 0) + $quantity;
            }
        }

        if ($added !== []) {
            $this->stock->syncQuantities([], $added);
        }

        $target = $target->fresh(['items', 'adjustments', 'courier']);
        $subtotal = (float) $target->items->sum(fn (OrderProduct $item) => (float) $item->line_total);
        $money = $this->totals->calculate(
            $subtotal,
            (float) $target->delivery_charge,
            (float) $target->courier_charge,
            $target->adjustments,
            $target->items,
            (float) $target->collected_amount,
            $target->courier?->slug,
            (float) ($target->courier?->cod_percentage ?? 1),
            (float) $target->packaging_cost,
        );

        $target->forceFill([
            'subtotal' => $money->subtotal,
            'total' => $money->total,
            'updated_by' => $userId,
        ])->save();

        $this->payments->sync($target->fresh());

        $note = trim((string) $target->admin_note);
        $target->forceFill([
            'admin_note' => $note === '' ? $historyNote : $note."\n".$historyNote,
        ])->save();

        $this->statusService->record($target->fresh(), $historyNote, $userId);

        return $target->fresh(['items']);
    }

    /**
     * @return array{product_id:?int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,unit_cost:?float,base_price:float,commission_rate:float,max_discount:mixed}
     */
    private function lineFromItem(OrderProduct $item): array
    {
        return [
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'name' => (string) $item->name,
            'quantity' => (int) $item->quantity,
            'price' => (float) $item->price,
            'purchase_price' => (float) $item->purchase_price,
            'line_total' => (float) $item->line_total,
            'product_image' => $item->product_image,
            'unit_cost' => $item->unit_cost !== null ? (float) $item->unit_cost : null,
            'base_price' => (float) $item->base_price,
            'commission_rate' => (float) $item->commission_rate,
            'max_discount' => $item->max_discount,
        ];
    }
}
