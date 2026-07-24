<?php

namespace App\Services\Channels;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Services\Admin\CustomerLookupService;
use App\Services\Admin\OrderStatusService;
use App\Services\Orders\OrderStockService;
use App\Services\Storefront\CheckoutPricing;
use App\Support\AdminOrderSegment;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChannelOrderDraftService
{
    public function __construct(
        private ChannelOrderParser $parser,
        private CustomerLookupService $customers,
        private OrderStockService $stock,
        private OrderStatusService $statusService,
    ) {}

    /**
     * Parse conversation and upsert a staff-only AI draft order linked to it.
     */
    public function syncDraftFromConversation(ChannelConversation $conversation): Order
    {
        $parsed = $this->parser->parseConversation($conversation);

        return DB::transaction(function () use ($conversation, $parsed) {
            $conversation->refresh();

            $existing = null;
            if ($conversation->draft_order_id) {
                $existing = Order::query()
                    ->whereKey($conversation->draft_order_id)
                    ->where('status', Order::STATUS_DRAFT)
                    ->first();
            }

            $orderData = $this->buildOrderAttributes($conversation, $parsed);
            $lines = $this->buildLines($parsed);

            if ($existing) {
                $existing->items()->delete();
                $existing->update($orderData);
                $this->persistLines($existing, $lines);
                $order = $existing->fresh(['items']);
            } else {
                $order = Order::query()->create(array_merge($orderData, [
                    'order_number' => 'PENDING',
                    'created_by' => null,
                    'updated_by' => null,
                ]));
                $order->update(['order_number' => (string) $order->id]);
                $this->persistLines($order, $lines);

                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status' => Order::STATUS_DRAFT,
                    'note' => 'AI draft created from '.$conversation->channel.' conversation.',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);

                $order = $order->fresh(['items']);
            }

            $conversation->forceFill([
                'draft_order_id' => $order->id,
                'customer_name' => $order->name !== 'Unknown' ? $order->name : $conversation->customer_name,
                'customer_phone' => filled($parsed['phone'] ?? null) ? $parsed['phone'] : $conversation->customer_phone,
            ])->save();

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $order;
        });
    }

    public function confirm(Order $order, ?int $confirmedBy = null): Order
    {
        if (! $order->isAiDraft()) {
            throw new InvalidArgumentException('Only AI draft orders can be confirmed.');
        }

        return DB::transaction(function () use ($order, $confirmedBy) {
            $order->load('items');

            $quantities = $this->stock->quantitiesFromOrder($order);
            $this->stock->syncQuantities([], $quantities);

            $phone = (string) $order->phone;
            $user = null;
            if (PhoneNumber::isValidDisplayMobile(PhoneNumber::display($phone))) {
                $user = $this->customers->findOrCreateCustomer($phone, (string) $order->name, $order->email);
            }

            $order = $this->statusService->update(
                $order,
                'new',
                'AI draft confirmed by staff.',
                $confirmedBy,
                array_filter([
                    'user_id' => $user?->id,
                    'updated_by' => $confirmedBy,
                    'placed_at' => $order->placed_at ?? now(),
                ], fn ($v) => $v !== null),
            );

            $conversation = $order->channelConversation;
            if ($conversation && (int) $conversation->draft_order_id === (int) $order->id) {
                $conversation->forceFill(['draft_order_id' => null])->save();
            }

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $order->fresh(['items', 'channelConversation.messages']);
        });
    }

    public function discard(Order $order): void
    {
        if (! $order->isAiDraft()) {
            throw new InvalidArgumentException('Only AI draft orders can be discarded this way.');
        }

        DB::transaction(function () use ($order) {
            $conversation = $order->channelConversation;
            if ($conversation && (int) $conversation->draft_order_id === (int) $order->id) {
                $conversation->forceFill(['draft_order_id' => null])->save();
            }

            // Drafts never reserved stock — delete without release.
            $order->items()->delete();
            $order->delete();

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function buildOrderAttributes(ChannelConversation $conversation, array $parsed): array
    {
        $name = filled($parsed['name'] ?? null)
            ? (string) $parsed['name']
            : (filled($conversation->customer_name) ? (string) $conversation->customer_name : 'Unknown');

        $phone = filled($parsed['phone'] ?? null)
            ? PhoneNumber::display((string) $parsed['phone'])
            : (filled($conversation->customer_phone)
                ? PhoneNumber::display((string) $conversation->customer_phone)
                : '00000000000');

        $city = null;
        $area = null;
        $cityId = isset($parsed['cityId']) ? (int) $parsed['cityId'] : null;
        $areaId = isset($parsed['areaId']) ? (int) $parsed['areaId'] : null;

        if ($areaId) {
            $areaModel = Area::query()->with('city')->find($areaId);
            if ($areaModel) {
                $area = $areaModel->name;
                $city = $areaModel->city?->name;
                $cityId = (int) $areaModel->city_id;
            }
        } elseif ($cityId) {
            $cityModel = City::query()->find($cityId);
            $city = $cityModel?->name;
        }

        $city = $city ?: ($parsed['city'] ?? null);
        $area = $area ?: ($parsed['area'] ?? null);

        $lines = $this->buildLines($parsed);
        $subtotal = (int) round(array_sum(array_map(fn (array $line) => (float) $line['line_total'], $lines)));
        $itemCount = (int) array_sum(array_map(fn (array $line) => (int) $line['quantity'], $lines));

        $location = null;
        if ($areaId) {
            $location = Area::query()->find($areaId);
        } elseif ($cityId) {
            $location = City::query()->find($cityId);
        } elseif (is_string($city) && $city !== '') {
            $location = $city;
        }

        $deliveryCharge = 0;
        if ($itemCount > 0 && $subtotal > 0 && $location) {
            $deliveryCharge = (int) round(CheckoutPricing::deliveryCharge($location, $itemCount, $subtotal));
        }

        $total = max(0, $subtotal + $deliveryCharge);
        $placedVia = $conversation->channel === ChannelConversation::CHANNEL_WHATSAPP
            ? Order::PLACED_VIA_WHATSAPP
            : Order::PLACED_VIA_MESSENGER;

        $missing = $parsed['missing'] ?? [];
        $adminNoteParts = ['Draft by AI ('.ucfirst($conversation->channel).')'];
        if (is_array($missing) && $missing !== []) {
            $adminNoteParts[] = 'Missing: '.implode(', ', $missing);
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'email' => null,
            'address' => filled($parsed['address'] ?? null) ? (string) $parsed['address'] : '',
            'area' => $area,
            'city' => $city,
            'state' => null,
            'delivery_type' => 'home',
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'charge' => 0,
            'discount' => 0,
            'total' => $total,
            'cod_amount' => $total,
            'due_amount' => $total,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => Order::STATUS_DRAFT,
            'admin_note' => implode('. ', $adminNoteParts).'.',
            'customer_note' => filled($parsed['raw_text'] ?? null)
                ? mb_substr((string) $parsed['raw_text'], 0, 2000)
                : null,
            'is_replacement' => false,
            'has_return' => false,
            'placed_at' => now(),
            'placed_via' => $placedVia,
            'channel_conversation_id' => $conversation->id,
            'ai_parse_meta' => [
                'source' => $parsed['source'] ?? 'none',
                'confidence' => $parsed['confidence'] ?? 0,
                'missing' => $missing,
                'product_name' => $parsed['product_name'] ?? null,
                'parsed_at' => now()->toIso8601String(),
            ],
            'user_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<array{product_id:?int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,base_price:float,commission_rate:float,max_discount:mixed}>
     */
    private function buildLines(array $parsed): array
    {
        $quantity = max(1, (int) ($parsed['quantity'] ?? 1));
        $productId = isset($parsed['product_id']) ? (int) $parsed['product_id'] : null;

        if ($productId) {
            $product = Product::query()->find($productId);
            if ($product) {
                $price = (float) (int) round((float) $product->price);
                $purchase = (float) (int) round((float) $product->purchase_price);

                return [[
                    'product_id' => (int) $product->id,
                    'name' => (string) $product->name,
                    'quantity' => $quantity,
                    'price' => $price,
                    'purchase_price' => $purchase,
                    'line_total' => $price * $quantity,
                    'product_image' => $product->primaryImagePath(),
                    'base_price' => $price,
                    'commission_rate' => (float) ($product->commission ?? 0),
                    'max_discount' => $product->max_discount,
                ]];
            }
        }

        $label = filled($parsed['product_name'] ?? null)
            ? (string) $parsed['product_name']
            : 'Unmatched product (AI draft)';

        return [[
            'product_id' => null,
            'name' => $label,
            'quantity' => $quantity,
            'price' => 0.0,
            'purchase_price' => 0.0,
            'line_total' => 0.0,
            'product_image' => null,
            'base_price' => 0.0,
            'commission_rate' => 0.0,
            'max_discount' => null,
        ]];
    }

    /**
     * @param  list<array{product_id:?int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,base_price?:float,commission_rate?:float,max_discount?:mixed}>  $lines
     */
    private function persistLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'product_image' => $line['product_image'],
                'quantity' => $line['quantity'],
                'base_price' => $line['base_price'] ?? $line['price'],
                'price' => $line['price'],
                'purchase_price' => $line['purchase_price'],
                'commission_rate' => $line['commission_rate'] ?? 0,
                'commission_earned' => 0,
                'max_discount' => isset($line['max_discount']) && $line['max_discount'] !== null
                    ? (float) $line['max_discount']
                    : null,
                'line_total' => $line['line_total'],
            ]);
        }
    }
}
