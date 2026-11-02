<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelOrderDraftCatcher
{
    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private ChannelOrderIntentGate $gate,
        private ChannelOrderDraftService $drafts,
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

        $conversation->load('messages');
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
}
