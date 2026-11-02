<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

class ChannelOrderIntentGate
{
    public function __construct(private ChannelOrderParser $parser) {}

    /**
     * True when recent inbound looks like an order close: Bangladesh mobile,
     * a selected product photo (or a reply to a priced/product image), and
     * a name or address cue. Price questions alone do not pass.
     */
    public function passes(ChannelConversation $conversation): bool
    {
        $inbound = $this->recentInbound($conversation);
        if ($inbound->isEmpty()) {
            return false;
        }

        $text = $this->joinBodies($inbound);
        if ($text === '' && ! $this->hasSelectedProductSignal($inbound)) {
            return false;
        }

        if (! PhoneNumber::extractFirstBangladeshMobile($text)) {
            return false;
        }

        if (! $this->parser->textHasNameCue($text) && ! $this->parser->textHasAddressCue($text)) {
            return false;
        }

        return $this->hasSelectedProductSignal($inbound);
    }

    /**
     * @return Collection<int, ChannelMessage>
     */
    public function recentInbound(ChannelConversation $conversation): Collection
    {
        $lookbackHours = max(1, (int) config('channels.ai_draft.lookback_hours', 48));
        $maxMessages = max(1, (int) config('channels.ai_draft.max_inbound_messages', 15));

        return ChannelMessage::query()
            ->with('replyTo')
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->where('sent_at', '>=', now()->subHours($lookbackHours))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($maxMessages)
            ->get()
            ->sortBy(fn (ChannelMessage $message) => [
                $message->sent_at?->getTimestamp() ?? 0,
                $message->id,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, ChannelMessage>  $inbound
     */
    public function hasSelectedProductSignal(Collection $inbound): bool
    {
        foreach ($inbound as $message) {
            if ((int) ($message->matched_product_id ?? 0) > 0) {
                return true;
            }

            $status = is_array($message->image_match_meta)
                ? (string) ($message->image_match_meta['status'] ?? '')
                : '';
            if (in_array($status, ['matched', 'tagged'], true)) {
                return true;
            }

            if ($message->isImageAttachment()) {
                return true;
            }

            $parent = $message->replyTo;
            if (! $parent || $parent->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
                continue;
            }

            if ((int) ($parent->matched_product_id ?? 0) > 0 || $parent->isImageAttachment()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, ChannelMessage>  $messages
     */
    private function joinBodies(Collection $messages): string
    {
        $chunks = [];
        foreach ($messages as $message) {
            $body = trim((string) ($message->body ?? ''));
            if ($body !== '') {
                $chunks[] = $body;
            }
        }

        return trim(implode("\n", $chunks));
    }
}
