<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Order;
use App\Services\Admin\InboxRecentOrders;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelOrderDraftCatcher
{
    /**
     * Admin "New" (new + confirmed) and "Dispatched" — do not auto-draft another order.
     *
     * @var list<string>
     */
    public const BLOCKING_STATUSES = ['new', 'confirmed', 'dispatched'];

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private ChannelOrderIntentGate $gate,
        private ChannelOrderDraftService $drafts,
        private InboxRecentOrders $recentOrders,
    ) {}

    /**
     * After the webhook response, parse a draft when the intent gate passes.
     * Never confirms or dispatches.
     */
    public static function scheduleAfterInbound(ChannelConversation $conversation): void
    {
        if (! config('channels.ai_draft.catch_on_inbound', true)) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $conversationId = (int) $conversation->id;
        if ($conversationId <= 0 || isset(self::$scheduled[$conversationId])) {
            return;
        }

        if (self::$scheduled === []) {
            app()->terminating(static function (): void {
                self::$scheduled = [];
            });
        }

        self::$scheduled[$conversationId] = true;

        dispatch(function () use ($conversationId): void {
            try {
                app(self::class)->catchFromId($conversationId);
            } catch (Throwable $e) {
                Log::warning('Inbox draft catcher failed.', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function catchFromId(int $conversationId): ?Order
    {
        $conversation = ChannelConversation::query()->find($conversationId);

        return $conversation ? $this->catch($conversation) : null;
    }

    public function catch(ChannelConversation $conversation): ?Order
    {
        if (! config('channels.ai_draft.catch_on_inbound', true)) {
            return null;
        }

        if (! $this->gate->passes($conversation)) {
            return null;
        }

        $conversation->loadMissing(['messages', 'draftOrder', 'user']);
        $blocking = $this->recentOrders->blockingOpenOrders(
            $conversation,
            $this->inboundPhones($conversation),
        );
        if ($blocking->isNotEmpty()) {
            $this->rememberBlock($conversation, $blocking->first());

            return null;
        }

        $this->clearBlock($conversation);

        $order = $this->drafts->syncDraftFromConversation($conversation);
        if (! $order || ! $order->isAiDraft()) {
            return $order;
        }

        $meta = is_array($order->ai_parse_meta) ? $order->ai_parse_meta : [];
        $meta['caught_via'] = 'intent_gate';
        $order->forceFill(['ai_parse_meta' => $meta])->save();

        return $order->fresh(['items']);
    }

    /**
     * Header chip payload when a draft looks ready for staff review.
     *
     * @return array{
     *     order_id: int,
     *     order_number: string,
     *     phone: string,
     *     area: string,
     *     items: string,
     *     can_confirm: bool,
     *     edit_url: string,
     *     show_url: string
     * }|null
     */
    public function readySummary(?ChannelConversation $conversation): ?array
    {
        $draft = $conversation?->draftOrder;
        if (! $draft || ! $draft->isAiDraft()) {
            return null;
        }

        $draft->loadMissing('items');
        $meta = is_array($draft->ai_parse_meta) ? $draft->ai_parse_meta : [];
        $source = (string) ($meta['source'] ?? 'staff');
        $caught = ($meta['caught_via'] ?? null) === 'intent_gate';
        if (! $caught && in_array($source, ['staff', 'none', ''], true)) {
            return null;
        }

        $phone = trim((string) ($draft->phone ?? ''));
        if ($phone === '' || ! PhoneNumber::isValidBangladeshMobile($phone)) {
            return null;
        }

        $hasCatalogProduct = $draft->items->contains(
            fn ($item): bool => (int) ($item->product_id ?? 0) > 0,
        );
        $hasAddress = filled($draft->address);
        if (! $hasCatalogProduct && ! $hasAddress) {
            return null;
        }

        $itemNames = $draft->items
            ->pluck('name')
            ->filter(fn ($name): bool => filled($name) && $name !== 'Unmatched product (AI draft)')
            ->take(2)
            ->implode(', ');

        $area = trim((string) ($draft->area ?: $draft->city ?: ''));

        return [
            'order_id' => (int) $draft->id,
            'order_number' => (string) $draft->order_number,
            'phone' => $phone,
            'area' => $area,
            'items' => $itemNames,
            'can_confirm' => filled($draft->city) && filled($draft->area),
            'edit_url' => route('admin.orders.edit', $draft),
            'show_url' => route('admin.orders.show', $draft),
        ];
    }

    /**
     * Header chip when auto-draft was skipped because a New or Dispatched order exists.
     *
     * @return array{order_id: int, order_number: string, status: string, label: string, show_url: string}|null
     */
    public function blockedSummary(?ChannelConversation $conversation): ?array
    {
        $stored = $conversation?->draftCatchBlock();
        if ($stored === null) {
            return null;
        }

        $order = Order::query()->find((int) $stored['order_id']);
        if (! $order || ! in_array((string) $order->status, self::BLOCKING_STATUSES, true)) {
            if ($conversation) {
                $this->clearBlock($conversation);
            }

            return null;
        }

        return $this->blockPayload($order);
    }

    /**
     * @return list<string>
     */
    private function inboundPhones(ChannelConversation $conversation): array
    {
        $chunks = [];
        foreach ($this->gate->recentInbound($conversation) as $message) {
            if (! $message instanceof ChannelMessage) {
                continue;
            }
            $body = trim((string) ($message->body ?? ''));
            if ($body !== '') {
                $chunks[] = $body;
            }
        }

        $extracted = PhoneNumber::extractFirstBangladeshMobile(implode("\n", $chunks));

        return $extracted ? [$extracted] : [];
    }

    private function rememberBlock(ChannelConversation $conversation, Order $order): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta[ChannelConversation::META_DRAFT_CATCH_BLOCKED] = $this->blockPayload($order);
        $conversation->forceFill(['meta' => $meta])->save();
    }

    private function clearBlock(ChannelConversation $conversation): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        if (! array_key_exists(ChannelConversation::META_DRAFT_CATCH_BLOCKED, $meta)) {
            return;
        }

        unset($meta[ChannelConversation::META_DRAFT_CATCH_BLOCKED]);
        $conversation->forceFill(['meta' => $meta === [] ? null : $meta])->save();
    }

    /**
     * @return array{order_id: int, order_number: string, status: string, label: string, show_url: string}
     */
    private function blockPayload(Order $order): array
    {
        $status = (string) $order->status;

        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => $status,
            'label' => $status === 'dispatched' ? 'Dispatched' : 'New',
            'show_url' => route('admin.orders.show', $order),
        ];
    }
}
