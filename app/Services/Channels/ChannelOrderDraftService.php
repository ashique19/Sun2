<?php

namespace App\Services\Channels;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
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
        private ChannelMessageOrderMapper $mapper,
        private CustomerLookupService $customers,
        private OrderStockService $stock,
        private OrderStatusService $statusService,
    ) {}

    /**
     * Parse recent conversation messages and upsert a staff-only AI draft when useful.
     *
     * Not called automatically from webhooks or Graph sync — Inbox staff drafts and
     * explicit parse paths own order creation. Returns null when there is no useful
     * order signal; existing drafts are left unchanged on weak parses.
     */
    public function syncDraftFromConversation(ChannelConversation $conversation): ?Order
    {
        $parsed = $this->parser->parseConversation($conversation);

        if (! $this->hasUsefulOrderSignal($parsed)) {
            if (! $conversation->draft_order_id) {
                return null;
            }

            return Order::query()
                ->whereKey($conversation->draft_order_id)
                ->where('status', Order::STATUS_DRAFT)
                ->first();
        }

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
                $orderData = $this->preserveStaffLockedFields($existing, $orderData, $lines);
                $locked = $this->staffLockedFields($existing);
                if (! in_array(ChannelMessageOrderMapper::FIELD_PRODUCT, $locked, true)) {
                    $existing->items()->delete();
                    $this->persistLines($existing, $lines);
                }
                $existing->update($orderData);
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
                // Prefer an existing Graph/webhook name over a weak AI guess from message text.
                'customer_name' => filled($conversation->customer_name)
                    ? $conversation->customer_name
                    : ($order->name !== 'Unknown' ? $order->name : $conversation->customer_name),
                'customer_phone' => filled($parsed['phone'] ?? null) ? $parsed['phone'] : $conversation->customer_phone,
            ])->save();

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $order;
        });
    }

    /**
     * Staff-initiated draft: create a minimal linked draft without AI confidence gating.
     */
    public function ensureDraftForConversation(ChannelConversation $conversation, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($conversation, $userId) {
            return $this->createOrReturnDraft($conversation, $userId);
        });
    }

    /**
     * Create or return the conversation's draft. Caller must own the transaction.
     */
    private function createOrReturnDraft(ChannelConversation $conversation, ?int $userId = null): Order
    {
        $conversation->refresh();

        if ($conversation->draft_order_id) {
            $existing = Order::query()
                ->whereKey($conversation->draft_order_id)
                ->where('status', Order::STATUS_DRAFT)
                ->first();

            if ($existing) {
                return $existing->loadMissing('items');
            }
        }

        $parsed = [
            'name' => filled($conversation->customer_name) ? $conversation->customer_name : null,
            'phone' => filled($conversation->customer_phone) ? $conversation->customer_phone : null,
            'address' => null,
            'product_id' => null,
            'product_name' => null,
            'quantity' => 1,
            'source' => 'staff',
            'confidence' => 0,
            'missing' => ['phone', 'address', 'product'],
            'weak_points' => [],
            'image_matches' => [],
            'raw_text' => null,
        ];

        if (filled($parsed['name'])) {
            $parsed['missing'] = array_values(array_diff($parsed['missing'], ['name']));
        }
        if (filled($parsed['phone']) && PhoneNumber::isValidBangladeshMobile((string) $parsed['phone'])) {
            $parsed['missing'] = array_values(array_diff($parsed['missing'], ['phone']));
        }

        $orderData = $this->buildOrderAttributes($conversation, $parsed);
        $orderData['admin_note'] = 'Draft started from Inbox by staff.';
        $orderData['ai_parse_meta'] = array_merge($orderData['ai_parse_meta'] ?? [], [
            'source' => 'staff',
            'staff_locked_fields' => [],
            'staff_mappings' => [],
        ]);

        $order = Order::query()->create(array_merge($orderData, [
            'order_number' => 'PENDING',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));
        $order->update(['order_number' => (string) $order->id]);
        $this->persistLines($order, $this->buildLines($parsed));

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => Order::STATUS_DRAFT,
            'note' => 'Staff draft created from '.$conversation->channel.' inbox.',
            'changed_by' => $userId,
            'created_at' => now(),
        ]);

        $conversation->forceFill([
            'draft_order_id' => $order->id,
        ])->save();

        Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

        return $order->fresh(['items']);
    }

    /**
     * Map a conversation message onto a draft order field (phone/name/address/product).
     *
     * @param  ?int  $productId  Required when field=product and multiple catalog matches exist.
     */
    public function applyMessageToField(
        ChannelConversation $conversation,
        ChannelMessage $message,
        string $field,
        ?int $productId = null,
        ?int $userId = null,
    ): Order {
        $field = $this->mapper->normalizeField($field);

        if ((int) $message->channel_conversation_id !== (int) $conversation->id) {
            throw new InvalidArgumentException('Message does not belong to this conversation.');
        }

        return DB::transaction(function () use ($conversation, $message, $field, $productId, $userId) {
            // Use the non-wrapping helper so draft create + field map stay one txn.
            $order = $this->createOrReturnDraft($conversation, $userId);
            $suggestion = $this->mapper->suggest($message, $field);
            $meta = is_array($order->ai_parse_meta) ? $order->ai_parse_meta : [];
            $locked = array_values(array_unique(array_merge(
                array_values($meta['staff_locked_fields'] ?? []),
                [$field],
            )));
            $mappings = array_values($meta['staff_mappings'] ?? []);

            $updates = [];

            if ($field === ChannelMessageOrderMapper::FIELD_PHONE) {
                $phone = $suggestion['value'];
                if (! $phone || ! PhoneNumber::isValidBangladeshMobile($phone)) {
                    throw new InvalidArgumentException('No valid Bangladesh mobile found in that message.');
                }
                $updates['phone'] = mb_substr(PhoneNumber::display($phone), 0, 32);
                $conversation->forceFill(['customer_phone' => $updates['phone']])->save();
            } elseif ($field === ChannelMessageOrderMapper::FIELD_NAME) {
                $name = trim((string) ($suggestion['value'] ?? $message->body ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Message has no text to use as a name.');
                }
                $updates['name'] = mb_substr($name, 0, 255);
                $conversation->forceFill(['customer_name' => $updates['name']])->save();
            } elseif ($field === ChannelMessageOrderMapper::FIELD_ADDRESS) {
                $address = trim((string) ($suggestion['value'] ?? $message->body ?? ''));
                if ($address === '') {
                    throw new InvalidArgumentException('Message has no text to use as an address.');
                }
                $updates['address'] = mb_substr($address, 0, 255);
                if ($suggestion['area_id']) {
                    $areaModel = Area::query()->with('city')->find((int) $suggestion['area_id']);
                    if ($areaModel) {
                        $updates['area'] = $areaModel->name;
                        $updates['city'] = $areaModel->city?->name;
                    }
                }
            } elseif ($field === ChannelMessageOrderMapper::FIELD_PRODUCT) {
                $resolvedProductId = $productId ?: $suggestion['product_id'];
                if (! $resolvedProductId && count($suggestion['products']) > 1) {
                    throw new InvalidArgumentException('Multiple products matched — pick one.');
                }

                $product = $resolvedProductId
                    ? Product::query()->find($resolvedProductId)
                    : null;

                $order->items()->delete();
                if ($product) {
                    $this->persistLines($order, $this->buildLines([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => 1,
                    ]));
                } else {
                    $label = trim((string) ($suggestion['value'] ?? $message->body ?? '')) ?: 'Unmatched product';
                    $this->persistLines($order, $this->buildLines([
                        'product_id' => null,
                        'product_name' => mb_substr($label, 0, 255),
                        'quantity' => 1,
                    ]));
                }
                $order = $order->fresh(['items']);
                $this->recalculateDraftTotals($order);
            }

            $mappings[] = [
                'field' => $field,
                'message_id' => $message->id,
                'value' => $suggestion['value'],
                'product_id' => $productId ?: $suggestion['product_id'],
                'user_id' => $userId,
                'applied_at' => now()->toIso8601String(),
            ];

            $meta['staff_locked_fields'] = $locked;
            $meta['staff_mappings'] = array_slice($mappings, -20);
            $meta['source'] = ($meta['source'] ?? 'none') === 'none' ? 'staff' : $meta['source'];

            if ($updates !== []) {
                $order->forceFill($updates);
            }

            $order->forceFill([
                'ai_parse_meta' => $meta,
                'updated_by' => $userId,
            ])->save();

            if ($field === ChannelMessageOrderMapper::FIELD_ADDRESS || $field === ChannelMessageOrderMapper::FIELD_PHONE) {
                $this->recalculateDraftTotals($order->fresh(['items']));
            }

            Cache::forget(AdminOrderSegment::COUNTS_CACHE_KEY);

            return $order->fresh(['items']);
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function hasUsefulOrderSignal(array $parsed): bool
    {
        $rawText = trim((string) ($parsed['raw_text'] ?? ''));
        if ($rawText === '' && empty($parsed['product_id'])) {
            return false;
        }

        $phone = isset($parsed['phone']) ? (string) $parsed['phone'] : '';
        $requirePhone = (bool) config('channels.ai_draft.require_phone', true);
        if ($requirePhone && ($phone === '' || ! PhoneNumber::isValidBangladeshMobile($phone))) {
            return false;
        }

        $minConfidence = (float) config('channels.ai_draft.min_confidence', 0.5);

        return (float) ($parsed['confidence'] ?? 0) >= $minConfidence;
    }

    /**
     * @return list<string>
     */
    private function staffLockedFields(Order $order): array
    {
        $meta = is_array($order->ai_parse_meta) ? $order->ai_parse_meta : [];

        return array_values(array_filter(
            array_map('strval', $meta['staff_locked_fields'] ?? []),
            fn (string $field) => in_array($field, ChannelMessageOrderMapper::FIELDS, true),
        ));
    }

    /**
     * Keep staff-mapped columns (and product lines) when AI re-syncs an existing draft.
     *
     * @param  array<string, mixed>  $orderData
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function preserveStaffLockedFields(Order $existing, array $orderData, array &$lines): array
    {
        $locked = $this->staffLockedFields($existing);
        $meta = is_array($orderData['ai_parse_meta'] ?? null) ? $orderData['ai_parse_meta'] : [];
        $existingMeta = is_array($existing->ai_parse_meta) ? $existing->ai_parse_meta : [];

        $meta['staff_locked_fields'] = $locked;
        $meta['staff_mappings'] = array_values($existingMeta['staff_mappings'] ?? []);
        $orderData['ai_parse_meta'] = $meta;

        if (in_array(ChannelMessageOrderMapper::FIELD_NAME, $locked, true)) {
            $orderData['name'] = $existing->name;
        }
        if (in_array(ChannelMessageOrderMapper::FIELD_PHONE, $locked, true)) {
            $orderData['phone'] = $existing->phone;
        }
        if (in_array(ChannelMessageOrderMapper::FIELD_ADDRESS, $locked, true)) {
            $orderData['address'] = $existing->address;
            $orderData['area'] = $existing->area;
            $orderData['city'] = $existing->city;
        }
        if (in_array(ChannelMessageOrderMapper::FIELD_PRODUCT, $locked, true)) {
            // Keep existing lines; totals already recalculated on the draft.
            $lines = [];
            $orderData['subtotal'] = $existing->subtotal;
            $orderData['delivery_charge'] = $existing->delivery_charge;
            $orderData['total'] = $existing->total;
            $orderData['cod_amount'] = $existing->cod_amount;
            $orderData['due_amount'] = $existing->due_amount;
        }

        return $orderData;
    }

    private function recalculateDraftTotals(Order $order): void
    {
        $order->loadMissing('items');
        $subtotal = (int) round($order->items->sum(fn (OrderProduct $item) => (float) $item->line_total));
        $itemCount = (int) $order->items->sum('quantity');

        $location = null;
        if (filled($order->area) && filled($order->city)) {
            $location = Area::query()
                ->where('name', $order->area)
                ->whereHas('city', fn ($q) => $q->where('name', $order->city))
                ->first();
        }
        if (! $location && filled($order->city)) {
            $location = City::query()->where('name', $order->city)->first() ?? (string) $order->city;
        }

        $deliveryCharge = 0;
        if ($itemCount > 0 && $subtotal > 0 && $location) {
            $deliveryCharge = (int) round(CheckoutPricing::deliveryCharge($location, $itemCount, $subtotal));
        }

        $total = max(0, $subtotal + $deliveryCharge);
        $order->forceFill([
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'total' => $total,
            'cod_amount' => $total,
            'due_amount' => $total,
        ])->save();
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
        $placedVia = match ($conversation->channel) {
            ChannelConversation::CHANNEL_MESSENGER => Order::PLACED_VIA_MESSENGER,
            default => Order::PLACED_VIA_ADMIN,
        };

        $missing = $parsed['missing'] ?? [];
        $adminNoteParts = ['Draft by AI ('.ucfirst($conversation->channel).')'];
        if (is_array($missing) && $missing !== []) {
            $adminNoteParts[] = 'Missing: '.implode(', ', $missing);
        }
        $weakPoints = array_values($parsed['weak_points'] ?? []);
        if ($weakPoints !== []) {
            $adminNoteParts[] = 'Weak: '.implode(', ', array_slice($weakPoints, 0, 6));
        }

        $address = filled($parsed['address'] ?? null) ? (string) $parsed['address'] : '';

        return [
            // Keep within orders string column limits (MySQL VARCHAR) so long
            // Messenger parses cannot fail inbox sync with SQL 1406.
            'name' => mb_substr($name, 0, 255),
            'phone' => mb_substr($phone, 0, 32),
            'email' => null,
            'address' => mb_substr($address, 0, 255),
            'area' => is_string($area) ? mb_substr($area, 0, 255) : $area,
            'city' => is_string($city) ? mb_substr($city, 0, 255) : $city,
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
            // Courier special-instruction field — never dump the Messenger transcript here.
            'customer_note' => null,
            'is_replacement' => false,
            'has_return' => false,
            'placed_at' => now(),
            'placed_via' => $placedVia,
            'channel_conversation_id' => $conversation->id,
            'ai_parse_meta' => [
                'source' => $parsed['source'] ?? 'none',
                'confidence' => $parsed['confidence'] ?? 0,
                'missing' => $missing,
                'weak_points' => array_values($parsed['weak_points'] ?? []),
                'product_name' => $parsed['product_name'] ?? null,
                'image_matches' => array_values($parsed['image_matches'] ?? []),
                'raw_text' => filled($parsed['raw_text'] ?? null)
                    ? mb_substr((string) $parsed['raw_text'], 0, 2000)
                    : null,
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
                $unitCost = (float) (int) round($product->effectiveUnitCost());

                return [[
                    'product_id' => (int) $product->id,
                    'name' => (string) $product->name,
                    'quantity' => $quantity,
                    'price' => $price,
                    'purchase_price' => $purchase,
                    'unit_cost' => $unitCost,
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
            'unit_cost' => 0.0,
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
                'unit_cost' => array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null
                    ? (float) $line['unit_cost']
                    : (float) $line['purchase_price'],
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
