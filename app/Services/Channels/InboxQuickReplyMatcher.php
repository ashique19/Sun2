<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Product;
use Illuminate\Support\Collection;

class InboxQuickReplyMatcher
{
    public const INTENT_PRICE = 'price';

    public const INTENT_DELIVERY = 'delivery';

    public const INTENT_ORDER = 'order';

    public const INTENT_NEGOTIATE = 'negotiate';

    public const VIA_TAGGED = 'tagged';

    public const VIA_EXAMPLE = 'example';

    public const VIA_INTENT = 'intent';

    /** Send every unreplied inbound photo whose catalog match is strictly above this. */
    public const PRICED_IMAGE_BULK_MIN_PERCENT = 84.0;

    /**
     * Compacted labels/bodies that ask which catalog item — not a price quote.
     *
     * @var list<string>
     */
    private const WHICH_PRODUCT_CUES = [
        'কোনপ্রডাক্ট', 'কোনপ্রোডাক্ট', 'কোনপণ্য',
        'whichproduct', 'konproduct', 'konprodakt', 'konprodact',
    ];

    /**
     * Compacted inbound greetings that map to the “Hi” saved reply.
     *
     * @var list<string>
     */
    private const HI_CUES = [
        'hi', 'hii', 'hiii', 'hiiii',
        'hello', 'helo', 'hellow', 'hey',
        'hlw', 'hlo', 'hy',
    ];

    /**
     * Compacted cues that map to the “কালার গ্যারান্টি” saved reply.
     *
     * @var list<string>
     */
    private const COLOR_GUARANTEE_CUES = [
        'কালারগ্যারান্টি', 'কালারগ্যারেন্টি',
        'colorguarantee', 'colourguarantee',
        'colorgaranty', 'colourgaranty',
        'kalarguarantee', 'kalargarranty',
    ];

    public const COLOR_GUARANTEE_LABEL = 'কালার গ্যারান্টি';

    public const PAHARI_ALCHORA_CATEGORY = 'পাহাড়ি আলছড়া';

    public const DELIVERY_CHARGE_LABEL = 'ডেলিভারি চার্জ ও সময়';

    /**
     * Compacted inbound wording that is haggling, not asking what the price is.
     *
     * @var list<string>
     */
    private const PRICE_NEGOTIATE_CUES = [
        'discount', 'discoun', 'diskaunt', 'discunt', 'descount',
        'lastprice', 'lastprise', 'lastprize',
        'negotiate', 'nego', 'bargain', 'bargen',
        'cheaper', 'reduceprice', 'lessprice',
        'komu', 'koman', 'komaben', 'komabe', 'komao',
        'ektukom', 'ettukom', 'kichukom', 'kicukom',
        'damkom', 'damkomu', 'damkoman',
        'offerdan', 'offerden', 'offerdib', 'giveoffer',
        'fixed', 'pricefixed', 'pricefix', 'fixprice', 'fixedprice',
        'priceisfixed', 'isfixed', 'damfix', 'damfixed',
        'kotolast', 'lastkoto', 'lastdam',
        'fixkor', 'fixkore', 'fixkoren',
        'ডিসকাউন্ট', 'ডিস্কাউন্ট', 'ডিসকাউন',
        'ছাড়দেন', 'ছাড়দেন', 'ছাড়দিবেন', 'ছাড়দিবেন',
        'কমাবেন', 'কমাবে', 'কমান', 'কমাও', 'কমায়', 'কমায়', 'কমাই',
        'কমদাম', 'কমদিবেন', 'কমদিবে', 'কমদেন', 'কমদিয়ে', 'কমদিয়ে',
        'কিছুকম', 'একটুকম',
        'ফিক্সড', 'দামফিক্স', 'দামfix', 'দামfixed',
    ];

    /**
     * Bootstrap cues for the three query types staff see most.
     * Compacted (lowercase, no spaces/punct) so typos like "eta koto" / "etakoto" still hit.
     *
     * @var array<string, list<string>>
     */
    private const CUES = [
        self::INTENT_PRICE => [
            'price', 'prize', 'pris', 'prce', 'prate', 'rate',
            'dam', 'damm', 'damkoto', 'kotodam', 'koto tk', 'kototk', 'kototaka',
            'howmuch', 'hmuch', 'pricekoto', 'prizkoto', 'prizekoto',
            'etakoto', 'eitakoto', 'eidakoto', 'eitakoto', 'idakoto',
            'দাম', 'কত', 'কতো', 'টাকা', 'মূল্য', 'দামকত', 'দামকতো',
            'এটাকত', 'এটাকতো', 'এইটাকত', 'এইটাকতো', 'এইডাকত', 'এটারদাম',
        ],
        self::INTENT_DELIVERY => [
            'delivery', 'delivary', 'dilevary', 'dilevery', 'delivry', 'delevery',
            'deliverytime', 'koto din', 'kotodin', 'kotosomoy', 'kobe pabo', 'kobepabo',
            'kobe', 'pouchabe', 'reach',
            'কবে', 'পাবো', 'পাব', 'কতদিন', 'কতদিনে', 'সময়', 'সময়',
            'পৌঁছাবে', 'পৌছাবে', 'ডেলিভারি', 'ডেলিভারী', 'ডেলিবারি', 'কবেপাবো', 'কবেপাব',
        ],
        self::INTENT_ORDER => [
            'howtoorder', 'howorder', 'kivabe', 'kivabeorder', 'orderkorbo', 'oderkorbo',
            'orderprocess', 'kivabenibo', 'booking',
            'placeorder', 'wanttoorder', 'wannaorder', 'orderplease',
            'orderkorte', 'orderkortechai', 'orderdibo', 'orderdao', 'orderden',
            'amiorder',
            'কিভাবে', 'কিভাবেঅর্ডার', 'অর্ডারকরব', 'অর্ডারকরবো', 'কিভাবেনিব', 'কিভাবেঅর্ডারকরব',
            'অর্ডারকিভাবে', 'অর্ডারপ্রসেস', 'অর্ডারকরতে', 'অর্ডারকরতেচাই',
            'অর্ডারদিব', 'অর্ডারদিতেচাই', 'অর্ডারচাই',
        ],
        self::INTENT_NEGOTIATE => self::PRICE_NEGOTIATE_CUES,
    ];

    public function __construct(
        private InboxQuickReplyService $quickReplies,
    ) {}

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     * @return array<int, array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}>
     */
    public function matchThread(ChannelConversation $conversation, array $replies): array
    {
        $messages = $conversation->messages
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->sortBy(fn (ChannelMessage $message) => [$message->sent_at?->getTimestamp() ?? 0, $message->id])
            ->values();

        if ($messages->isEmpty() || $replies === []) {
            return [];
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($replies, 'id'))
            ->get();

        $hasInboundProductImages = $this->threadHasInboundProductImages($conversation);

        $out = [];
        foreach ($messages as $message) {
            $match = $this->matchMessage($message, $replies, $examples, $hasInboundProductImages);
            if ($match !== null && $this->shouldSuppressColorGuaranteeMatch($conversation, $match)) {
                continue;
            }
            if ($match !== null) {
                $out[(int) $message->id] = $match;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     * @return array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}|null
     */
    public function matchMessage(
        ChannelMessage $message,
        array $replies,
        Collection $examples,
        bool $hasInboundProductImages = false,
    ): ?array {
        $repliesById = [];
        foreach ($replies as $reply) {
            $repliesById[$reply['id']] = $reply;
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '' && isset($repliesById[$taggedId])) {
            return [
                'id' => $taggedId,
                'label' => $repliesById[$taggedId]['label'],
                'body' => $repliesById[$taggedId]['body'],
                'via' => self::VIA_TAGGED,
                'intent' => $this->intentForReply($repliesById[$taggedId]),
                'tagged' => true,
            ];
        }

        $haystack = trim((string) ($message->body ?? ''));
        if ($haystack === '') {
            return null;
        }

        $exampleHit = $this->matchExamples($haystack, $examples, $repliesById);
        if (
            $exampleHit !== null
            && ! $this->shouldSuppressWhichProductMatch($message, $exampleHit, $hasInboundProductImages)
        ) {
            return $exampleHit;
        }

        $colorHit = $this->matchColorGuarantee($haystack, $replies);
        if ($colorHit !== null) {
            return $colorHit;
        }

        return $this->matchIntent($haystack, $replies, $message, $hasInboundProductImages);
    }

    /**
     * Staff tag: bind this inbound message to a saved quick reply and keep the
     * customer wording as a future match example. Never sends a reply.
     */
    public function tag(ChannelMessage $message, string $quickReplyId, ?int $userId = null): void
    {
        $quickReplyId = trim($quickReplyId);
        if ($quickReplyId === '' || $message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        $text = trim((string) ($message->body ?? ''));

        $message->quick_reply_id = $quickReplyId;
        $message->save();

        if ($text === '') {
            InboxQuickReplyExample::query()
                ->where('channel_message_id', $message->id)
                ->delete();

            return;
        }

        InboxQuickReplyExample::query()->updateOrCreate(
            ['channel_message_id' => $message->id],
            [
                'quick_reply_id' => $quickReplyId,
                'example_text' => mb_substr($text, 0, 2000),
                'example_normalized' => mb_substr($this->normalize($text), 0, 191),
                'tagged_by' => $userId,
            ],
        );
    }

    public function clearTag(ChannelMessage $message): void
    {
        $message->quick_reply_id = null;
        $message->save();

        InboxQuickReplyExample::query()
            ->where('channel_message_id', $message->id)
            ->delete();
    }

    /**
     * Staff-authored training phrase, not tied to a chat message.
     */
    public function addExample(string $quickReplyId, string $text, ?int $userId = null): ?InboxQuickReplyExample
    {
        $quickReplyId = trim($quickReplyId);
        $text = trim($text);
        if ($quickReplyId === '' || $text === '') {
            return null;
        }

        $normalized = mb_substr($this->normalize($text), 0, 191);
        if ($normalized === '') {
            return null;
        }

        $existing = InboxQuickReplyExample::query()
            ->where('quick_reply_id', $quickReplyId)
            ->where('example_normalized', $normalized)
            ->first();
        if ($existing) {
            return $existing;
        }

        return InboxQuickReplyExample::query()->create([
            'quick_reply_id' => $quickReplyId,
            'example_text' => mb_substr($text, 0, 2000),
            'example_normalized' => $normalized,
            'channel_message_id' => null,
            'tagged_by' => $userId,
        ]);
    }

    public function updateExample(InboxQuickReplyExample $example, string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $normalized = mb_substr($this->normalize($text), 0, 191);
        if ($normalized === '') {
            return false;
        }

        $duplicate = InboxQuickReplyExample::query()
            ->where('quick_reply_id', $example->quick_reply_id)
            ->where('example_normalized', $normalized)
            ->whereKeyNot($example->id)
            ->exists();
        if ($duplicate) {
            return false;
        }

        $example->forceFill([
            'example_text' => mb_substr($text, 0, 2000),
            'example_normalized' => $normalized,
        ])->save();

        return true;
    }

    public function deleteExample(InboxQuickReplyExample $example): void
    {
        $messageId = (int) ($example->channel_message_id ?? 0);
        $example->delete();

        if ($messageId <= 0) {
            return;
        }

        $message = ChannelMessage::query()->find($messageId);
        if ($message instanceof ChannelMessage) {
            $message->quick_reply_id = null;
            $message->save();
        }
    }

    /**
     * Drop training phrases whose quick reply was removed.
     *
     * @param  list<string>  $validReplyIds
     */
    public function pruneExamples(array $validReplyIds): void
    {
        $valid = array_values(array_filter($validReplyIds, fn (mixed $id): bool => is_string($id) && $id !== ''));

        $query = InboxQuickReplyExample::query();
        if ($valid === []) {
            $query->delete();

            return;
        }

        $query->whereNotIn('quick_reply_id', $valid)->delete();
    }

    public function detectIntent(string $text): ?string
    {
        $compact = $this->compact($text);
        if ($compact === '') {
            return null;
        }

        $bestIntent = null;
        $bestLen = 0;
        foreach (self::CUES as $intent => $cues) {
            foreach ($cues as $cue) {
                $needle = $this->compact($cue);
                if ($needle === '' || mb_strlen($needle) < 2) {
                    continue;
                }
                if (! str_contains($compact, $needle)) {
                    continue;
                }
                $len = mb_strlen($needle);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestIntent = $intent;
                }
            }
        }

        return $bestIntent;
    }

    public function looksLikePriceQuery(string $text): bool
    {
        if ($this->looksLikePriceNegotiate($text)) {
            return false;
        }

        return $this->detectIntent($text) === self::INTENT_PRICE;
    }

    /**
     * True when the customer is pushing for a cheaper rate, not asking the price.
     */
    public function looksLikePriceNegotiate(string $text): bool
    {
        $compact = $this->compact($text);
        if ($compact === '') {
            return false;
        }

        foreach (self::PRICE_NEGOTIATE_CUES as $cue) {
            $needle = $this->compact($cue);
            if ($needle !== '' && str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this inbound is a price / which-product ask: staff-tagged against
     * the “কোন প্রডাক্ট?” reply, a saved reply tag, or price-shaped wording.
     */
    public function isWhichProductPriceAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($replies as $reply) {
                if ($reply['id'] === $taggedId && $this->isWhichProductReply($reply)) {
                    return true;
                }
            }
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikePriceQuery($text)) {
            return true;
        }

        if ($text === '' || $replies === []) {
            return false;
        }

        $ids = [];
        foreach ($replies as $reply) {
            if ($this->isWhichProductReply($reply)) {
                $ids[] = $reply['id'];
            }
        }

        $examples = $ids === []
            ? collect()
            : InboxQuickReplyExample::query()->whereIn('quick_reply_id', $ids)->get();

        $match = $this->matchMessage($message, $replies, $examples, hasInboundProductImages: false);

        return is_array($match) && $this->isWhichProductReply($match);
    }

    /**
     * True when this saved reply asks which product, rather than quoting a price.
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    /**
     * True when this inbound is a “Hi” greeting: tagged against the Hi saved
     * reply, a Hi example tag, or short hi/hello wording while that chip exists.
     */
    public function isHiAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $hiReplies = [];
        foreach ($replies as $reply) {
            if ($this->isHiReply($reply)) {
                $hiReplies[] = $reply;
            }
        }

        if ($hiReplies === []) {
            return false;
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($hiReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            return false;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeHi($text)) {
            return true;
        }

        if ($text === '') {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($hiReplies, 'id'))
            ->get();
        $match = $this->matchMessage($message, $hiReplies, $examples, hasInboundProductImages: false);

        return is_array($match) && $this->isHiReply($match);
    }

    public function looksLikeHi(string $text): bool
    {
        $compact = $this->compact($text);

        return $compact !== '' && in_array($compact, self::HI_CUES, true);
    }

    /**
     * True when this saved reply is the Hi greeting chip (label “Hi”).
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isHiReply(array $reply): bool
    {
        return $this->compact((string) ($reply['label'] ?? '')) === 'hi';
    }

    /**
     * True when this inbound is haggling: tagged against a fixed-price chip,
     * the inbox matcher hitting that chip (including English “price fixed”),
     * a saved negotiate example, or discount / “komu” wording.
     */
    public function isPriceNegotiateAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $negotiateReplies = [];
        foreach ($replies as $reply) {
            if ($this->isPriceNegotiateReply($reply)) {
                $negotiateReplies[] = $reply;
            }
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($negotiateReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            return false;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikePriceNegotiate($text)) {
            return true;
        }

        if ($text === '' || $replies === []) {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($replies, 'id'))
            ->get();
        $match = $this->matchMessage($message, $replies, $examples, hasInboundProductImages: false);

        return is_array($match) && $this->isPriceNegotiateReply($match);
    }

    /**
     * True when this saved reply is a fixed-price / no-discount chip,
     * including the English “price fixed” label.
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isPriceNegotiateReply(array $reply): bool
    {
        $haystack = $this->compact(($reply['label'] ?? '').' '.($reply['body'] ?? ''));
        if ($haystack === '') {
            return false;
        }

        foreach (self::PRICE_NEGOTIATE_CUES as $cue) {
            $needle = $this->compact($cue);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this inbound is placing / asking how to order: tagged against
     * the “অর্ডার প্রসেস” chip, a saved example, or order-process wording.
     */
    public function isOrderProcessAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $orderReplies = [];
        foreach ($replies as $reply) {
            if ($this->isOrderProcessReply($reply)) {
                $orderReplies[] = $reply;
            }
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($orderReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            return false;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeOrderProcess($text)) {
            return true;
        }

        if ($text === '' || $replies === []) {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($replies, 'id'))
            ->get();
        $match = $this->matchMessage($message, $replies, $examples, hasInboundProductImages: false);

        return is_array($match) && $this->isOrderProcessReply($match);
    }

    public function looksLikeOrderProcess(string $text): bool
    {
        $compact = $this->compact($text);
        if ($compact === '') {
            return false;
        }

        foreach (self::CUES[self::INTENT_ORDER] as $cue) {
            $needle = $this->compact($cue);
            if ($needle === '' || mb_strlen($needle) < 2) {
                continue;
            }
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this saved reply is the “অর্ডার প্রসেস” / how-to-order chip.
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isOrderProcessReply(array $reply): bool
    {
        $label = $this->compact((string) ($reply['label'] ?? ''));
        if ($label === '') {
            return false;
        }

        if ($label === 'order' || $label === $this->compact(InboxOrderProcessAutomationService::REPLY_LABEL)) {
            return true;
        }

        foreach (['অর্ডারপ্রসেস', 'orderprocess', 'howtoorder', 'কিভাবেঅর্ডার', 'অর্ডারকিভাবে', 'kivabeorder'] as $cue) {
            $needle = $this->compact($cue);
            if ($needle !== '' && str_contains($label, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     * @return array{id: string, label: string, body: string}|null
     */
    private function replyById(array $replies, string $id): ?array
    {
        foreach ($replies as $reply) {
            if ($reply['id'] === $id) {
                return $reply;
            }
        }

        return null;
    }

    public function isDeliveryChargeAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $deliveryReplies = [];
        foreach ($replies as $reply) {
            if ($this->isDeliveryChargeReply($reply)) {
                $deliveryReplies[] = $reply;
            }
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($deliveryReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            $taggedReply = $this->replyById($replies, $taggedId);
            if ($taggedReply === null || ! $this->isOrderProcessReply($taggedReply)) {
                return false;
            }
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeDeliveryCharge($text)) {
            return true;
        }

        if ($text === '' || $deliveryReplies === []) {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($deliveryReplies, 'id'))
            ->get();
        $repliesById = [];
        foreach ($deliveryReplies as $reply) {
            $repliesById[$reply['id']] = $reply;
        }
        $exampleHit = $this->matchExamples($text, $examples, $repliesById);

        return is_array($exampleHit) && $this->isDeliveryChargeReply($exampleHit);
    }

    public function looksLikeDeliveryCharge(string $text): bool
    {
        if ($this->looksLikeAdvance($text) || $this->looksLikeCodPush($text)) {
            return false;
        }

        return $this->hasDeliveryChargeCue($text);
    }

    /**
     * Delivery time / charge wording, even when the same inbound also asks about advance.
     */
    public function hasDeliveryTimeOrChargeCue(string $text): bool
    {
        return $this->looksLikeKobePabo($text) || $this->hasDeliveryChargeCue($text);
    }

    private function hasDeliveryChargeCue(string $text): bool
    {
        $compact = $this->compactYa($text);
        if ($compact === '') {
            return false;
        }

        $labelNeedle = $this->compactYa(self::DELIVERY_CHARGE_LABEL);
        if ($labelNeedle !== '' && str_contains($compact, $labelNeedle)) {
            return true;
        }

        if (str_contains($compact, $this->compactYa('পার্সেল')) && str_contains($compact, $this->compactYa('পাঠা'))) {
            return false;
        }

        foreach (['tracking', 'ট্রাকিং', 'ট্র্যাকিং'] as $tracking) {
            $needle = $this->compactYa($tracking);
            if ($needle !== '' && str_contains($compact, $needle)) {
                return false;
            }
        }

        $hasDelivery = str_contains($compact, $this->compactYa('ডেলিভারি'))
            || str_contains($compact, $this->compactYa('ডেলিভারী'))
            || str_contains($compact, $this->compactYa('ডেলিবারি'))
            || str_contains($compact, 'delivery')
            || str_contains($compact, 'delivary')
            || str_contains($compact, 'dilevery');
        $hasCharge = str_contains($compact, $this->compactYa('চার্জ'))
            || str_contains($compact, 'charge')
            || str_contains($compact, 'cost');
        $hasTime = str_contains($compact, $this->compactYa('সময়'))
            || str_contains($compact, $this->compactYa('সময়'))
            || str_contains($compact, 'time')
            || str_contains($compact, $this->compactYa('কতদিন'))
            || str_contains($compact, 'kotodin');
        $asksHowMuch = str_contains($compact, $this->compactYa('কত'))
            || str_contains($compact, $this->compactYa('কতো'))
            || str_contains($compact, 'koto');

        if ($hasDelivery && ($hasCharge || $hasTime || $asksHowMuch)) {
            return true;
        }

        return $hasTime && (str_contains($compact, $this->compactYa('কতদিন')) || str_contains($compact, 'kotodin'));
    }

    /**
     * Customer asking specifically when they will receive the parcel (“কবে পাব”),
     * not a charge/time-range question and not a parcel-dispatch “পাঠাবেন”.
     */
    public function looksLikeKobePabo(string $text): bool
    {
        if ($this->looksLikeCodPush($text)) {
            return false;
        }

        $compact = $this->compactYa($text);
        if ($compact === '') {
            return false;
        }

        if (str_contains($compact, $this->compactYa('পার্সেল')) && str_contains($compact, $this->compactYa('পাঠা'))) {
            return false;
        }

        if ($this->containsAnyCue($compact, ['পাঠা', 'patha', 'pathabo', 'pathaben', 'পাবেন'])) {
            return false;
        }

        if (! $this->containsAnyCue($compact, ['কবে', 'kobe'])) {
            return false;
        }

        if ($this->containsAnyCue($compact, ['পাবো', 'pabo', 'paba'])) {
            return true;
        }

        $pab = $this->compactYa('পাব');
        $paben = $this->compactYa('পাবেন');

        return $pab !== ''
            && str_contains($compact, $pab)
            && ($paben === '' || ! str_contains($compact, $paben));
    }

    /**
     * True when this saved reply is the delivery time/charge chip.
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isDeliveryChargeReply(array $reply): bool
    {
        $label = $this->compactYa((string) ($reply['label'] ?? ''));
        if ($label === '') {
            return false;
        }

        $canonical = $this->compactYa(self::DELIVERY_CHARGE_LABEL);
        if ($canonical !== '' && str_contains($label, $canonical)) {
            return true;
        }

        $hasDelivery = str_contains($label, $this->compactYa('ডেলিভারি'))
            || str_contains($label, $this->compactYa('ডেলিভারী'))
            || str_contains($label, 'delivery');
        $hasChargeOrTime = str_contains($label, $this->compactYa('চার্জ'))
            || str_contains($label, 'charge')
            || str_contains($label, $this->compactYa('সময়'))
            || str_contains($label, $this->compactYa('সময়'))
            || str_contains($label, 'time');

        return $hasDelivery && $hasChargeOrTime;
    }

    public function isLocationAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $locationReplies = [];
        foreach ($replies as $reply) {
            if ($this->isLocationReply($reply)) {
                $locationReplies[] = $reply;
            }
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($locationReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            return false;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeLocation($text)) {
            return true;
        }

        if ($text === '' || $locationReplies === []) {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($locationReplies, 'id'))
            ->get();
        $repliesById = [];
        foreach ($locationReplies as $reply) {
            $repliesById[$reply['id']] = $reply;
        }
        $exampleHit = $this->matchExamples($text, $examples, $repliesById);

        return is_array($exampleHit) && $this->isLocationReply($exampleHit);
    }

    public function looksLikeLocation(string $text): bool
    {
        if ($this->looksLikeAdvance($text) || $this->looksLikeCodPush($text) || $this->looksLikeDeliveryCharge($text) || $this->looksLikeKobePabo($text)) {
            return false;
        }

        $compact = $this->compactYa($text);
        if ($compact === '') {
            return false;
        }

        if ($this->containsAnyCue($compact, [
            'showroom', 'shooroom', 'sourum', 'sorom',
            'শোরুম',
        ])) {
            return true;
        }

        if ($this->containsAnyCue($compact, [
            'warehouse', 'warehous', 'godown',
            'ওয়ারহাউজ', 'ওয়ারহাউজ', 'ওয়্যারহাউস', 'ওয়ারহাউস',
            'গুদাম', 'গোডাউন',
        ])) {
            return true;
        }

        $hasOffice = $this->containsAnyCue($compact, ['office', 'অফিস']);
        $hasLocationWord = $this->containsAnyCue($compact, [
            'location', 'lokation', 'lokeshon', 'lokeson',
            'লোকেশন', 'লোকেশান',
        ]);
        $hasAddress = $this->containsAnyCue($compact, ['address', 'thikana', 'ঠিকানা']);
        $hasShop = $this->containsAnyCue($compact, ['outlet', 'দোকান']);
        $hasVisit = $this->containsAnyCue($compact, [
            'visit', 'ভিজিট', 'আসতেপারি', 'আসতেচাই',
            'নিজেনিতে', 'নিজেনিয়ে', 'নিজেনিয়ে',
            'pickup', 'পিকআপ', 'পিকআপপয়েন্ট',
        ]);
        $hasTheir = $this->containsAnyCue($compact, [
            'apnader', 'apnar', 'your',
            'আপনাদের', 'আপনার', 'তোমাদের',
        ]);
        $hasQuestion = $this->containsAnyCue($compact, [
            'kothay', 'kothai', 'koi', 'where', 'ache',
            'কোথায়', 'কোথায়', 'কই', 'কি',
        ]);
        $givingOwn = $this->containsAnyCue($compact, [
            'আমারঠিকানা', 'amarthikana', 'myaddress',
            'ঠিকানাদিলাম', 'ঠিকানাদিয়েছি', 'ঠিকানাদিয়েছি', 'ঠিকানাদিচ্ছি',
        ]);
        $orderLike = $hasAddress && $this->containsAnyCue($compact, [
            'নাম', 'মোবাইল', 'mobile', 'phone',
        ]);

        if ($givingOwn || $orderLike) {
            return false;
        }

        if ($hasOffice && ($hasQuestion || $hasTheir || $hasLocationWord || $hasAddress)) {
            return true;
        }

        if ($hasLocationWord && ($hasQuestion || $hasTheir || $this->isBareCueAsk($compact, [
            'location', 'lokation', 'lokeshon', 'lokeson',
            'লোকেশন', 'লোকেশান',
        ]))) {
            return true;
        }

        if ($hasAddress && ($hasTheir || ($hasQuestion && ! $this->containsAnyCue($compact, ['আমার', 'amar', 'my'])) || $this->isBareCueAsk($compact, [
            'address', 'thikana', 'ঠিকানা',
        ]))) {
            return true;
        }

        if ($hasShop && ($hasQuestion || $hasTheir)) {
            return true;
        }

        return $hasVisit && ($hasQuestion || $hasTheir || $hasShop || $hasOffice || $hasLocationWord);
    }

    /**
     * True when this saved reply is the shop-location chip (not the “Address?” customer-address chip).
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isLocationReply(array $reply): bool
    {
        $body = trim((string) ($reply['body'] ?? ''));
        if ($body !== '' && $body === InboxLocationAutomationService::BODY) {
            return true;
        }

        $label = $this->compactYa((string) ($reply['label'] ?? ''));
        if ($label === '') {
            return false;
        }

        if ($label === $this->compactYa('address') || $label === $this->compactYa('ঠিকানা')) {
            return false;
        }

        return $this->containsAnyCue($label, [
            'showroom', 'শোরুম',
            'location', 'লোকেশন',
            'warehouse', 'ওয়ারহাউজ',
        ]);
    }

    public function isAdvanceAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $advanceReplies = [];
        foreach ($replies as $reply) {
            if ($this->isAdvanceReply($reply)) {
                $advanceReplies[] = $reply;
            }
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($advanceReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            $taggedReply = $this->replyById($replies, $taggedId);
            if ($taggedReply === null || ! $this->isOrderProcessReply($taggedReply)) {
                return false;
            }
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeAdvance($text)) {
            return true;
        }

        if ($text === '' || $advanceReplies === []) {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($advanceReplies, 'id'))
            ->get();
        $repliesById = [];
        foreach ($advanceReplies as $reply) {
            $repliesById[$reply['id']] = $reply;
        }
        $exampleHit = $this->matchExamples($text, $examples, $repliesById);

        return is_array($exampleHit) && $this->isAdvanceReply($exampleHit);
    }

    public function looksLikeAdvance(string $text): bool
    {
        $compact = $this->compactYa($text);
        if ($compact === '') {
            return false;
        }

        if ($this->containsAnyCue($compact, [
            'এডভান্স', 'অ্যাডভান্স', 'অ্যাডভান্সড',
            'advance', 'advence', 'advanc',
            'prepaid',
            'বুকিংমানি', 'bookingmoney',
        ])) {
            return true;
        }

        $hasAge = $this->containsAnyCue($compact, ['আগে', 'age']);
        $hasPay = $this->containsAnyCue($compact, [
            'পাঠাতে', 'দিতে', 'দিব', 'দিবো', 'টাকা', 'পেমেন্ট', 'payment', 'pay',
        ]);
        $hasCharge = $this->containsAnyCue($compact, ['চার্জ', 'charge', 'ডেলিভারি', 'delivery']);
        if ($hasAge && $hasPay && $hasCharge) {
            return true;
        }

        return $this->containsAnyCue($compact, ['ক্যাশঅনডেলিভারি', 'cashondelivery'])
            && $this->containsAnyCue($compact, ['হবে', 'আছে', 'কি', 'naki', 'hobe', 'ache']);
    }

    public function looksLikeCodPush(string $text): bool
    {
        $compact = $this->compactYa($text);
        if ($compact === '') {
            return false;
        }

        if ($this->containsAnyCue($compact, [
            'পুরোক্যাশঅনডেলিভারি', 'পুরোক্যাশঅন', 'fullcashondelivery', 'fullcod',
            'পুরোcod', 'onlycod', 'mustcod',
            'এডভান্সছাড়া', 'advanceছাড়া', 'withoutadvance',
            'আগেদিবনা', 'আগেদিবোনা', 'advancedibona', 'agedibona',
            'নাদিয়েপাঠা', 'নাদিয়েপাঠা',
        ])) {
            return true;
        }

        $hasCod = $this->containsAnyCue($compact, ['ক্যাশঅনডেলিভারি', 'cashondelivery'])
            || preg_match('/(?<![a-z])cod(?!e)/', $compact) === 1;
        $wantsCod = $hasCod && $this->containsAnyCue($compact, ['চাই', 'pathao', 'পাঠা', 'করো', 'করুন', 'chai']);
        $fullLater = $this->containsAnyCue($compact, ['পুরোটাপরে', 'পুরোটাকাপরে', 'fullpore']);

        return $wantsCod || $fullLater;
    }

    /**
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isAdvanceReply(array $reply): bool
    {
        $body = trim((string) ($reply['body'] ?? ''));
        if ($body !== '' && (
            $body === InboxAdvanceAutomationService::BODY_ASK_LOCATION
            || $body === InboxAdvanceAutomationService::unlistedBody()
            || str_contains($body, 'ডেলিভারি চার্জ ১২০ টাকা আগে পাঠাতে হবে')
            || str_contains($body, 'এডভান্স ডেলিভারি চার্জ ছাড়া আমরা পাঠাব না')
        )) {
            return true;
        }

        $label = $this->compactYa((string) ($reply['label'] ?? ''));
        if ($label === '') {
            return false;
        }

        return $this->containsAnyCue($label, ['এডভান্স', 'অ্যাডভান্স', 'advance'])
            && ! $this->containsAnyCue($label, ['address', 'ঠিকানা']);
    }

    /**
     * @param  list<string>  $cues
     */
    private function containsAnyCue(string $compact, array $cues): bool
    {
        foreach ($cues as $cue) {
            $needle = $this->compactYa($cue);
            if ($needle !== '' && str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $cues
     */
    private function isBareCueAsk(string $compact, array $cues): bool
    {
        foreach ($cues as $cue) {
            $needle = $this->compactYa($cue);
            if ($needle === '') {
                continue;
            }
            if ($compact === $needle) {
                return true;
            }
            if (str_starts_with($compact, $needle) && mb_strlen($compact) <= mb_strlen($needle) + 6) {
                $rest = mb_substr($compact, mb_strlen($needle));

                return $rest === '' || $this->containsAnyCue($rest, ['please', 'pls', 'apu', 'আপু', 'কি', 'কই']);
            }
        }

        return false;
    }

    private function compactYa(string $text): string
    {
        return strtr($this->compact($text), [
            "\u{09DF}" => "\u{09AF}",
        ]);
    }

    public function isColorGuaranteeAsk(ChannelMessage $message): bool
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $replies = $this->quickReplies->all();
        $colorReplies = [];
        foreach ($replies as $reply) {
            if ($this->isColorGuaranteeReply($reply)) {
                $colorReplies[] = $reply;
            }
        }

        if ($colorReplies === []) {
            return false;
        }

        $taggedId = trim((string) ($message->quick_reply_id ?? ''));
        if ($taggedId !== '') {
            foreach ($colorReplies as $reply) {
                if ($reply['id'] === $taggedId) {
                    return true;
                }
            }

            return false;
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text !== '' && $this->looksLikeColorGuarantee($text)) {
            return true;
        }

        if ($text === '') {
            return false;
        }

        $examples = InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', array_column($colorReplies, 'id'))
            ->get();
        $match = $this->matchMessage($message, $colorReplies, $examples, hasInboundProductImages: false);

        return is_array($match) && $this->isColorGuaranteeReply($match);
    }

    public function looksLikeColorGuarantee(string $text): bool
    {
        $compact = $this->compact($text);
        if ($compact === '') {
            return false;
        }

        foreach (self::COLOR_GUARANTEE_CUES as $cue) {
            $needle = $this->compact($cue);
            if ($needle !== '' && str_contains($compact, $needle)) {
                return true;
            }
        }

        $labelNeedle = $this->compact(self::COLOR_GUARANTEE_LABEL);

        return $labelNeedle !== '' && str_contains($compact, $labelNeedle);
    }

    /**
     * True when this saved reply is the কালার গ্যারান্টি chip.
     *
     * @param  array{label?: string, body?: string}  $reply
     */
    public function isColorGuaranteeReply(array $reply): bool
    {
        $label = $this->compact((string) ($reply['label'] ?? ''));
        $needle = $this->compact(self::COLOR_GUARANTEE_LABEL);

        return $label !== '' && $needle !== '' && str_contains($label, $needle);
    }

    public function isPahariAlchoraCategoryName(string $name): bool
    {
        $compact = $this->foldBanglaRra($this->compact($name));
        if ($compact === '') {
            return false;
        }

        foreach ([self::PAHARI_ALCHORA_CATEGORY, 'পাহাড়ি আলছড়া', 'pahari alchora'] as $alias) {
            if ($compact === $this->foldBanglaRra($this->compact($alias))) {
                return true;
            }
        }

        return false;
    }

    public function sessionHasPahariAlchoraProduct(ChannelConversation $conversation): bool
    {
        $productIds = $this->sessionMatchedProductIds($conversation);
        if ($productIds === []) {
            return false;
        }

        return Product::query()
            ->with('category')
            ->whereIn('id', $productIds)
            ->get()
            ->contains(function (Product $product): bool {
                $categoryName = trim((string) ($product->category?->name ?? ''));

                return $categoryName !== '' && $this->isPahariAlchoraCategoryName($categoryName);
            });
    }

    /**
     * @return list<int>
     */
    private function sessionMatchedProductIds(ChannelConversation $conversation): array
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $messages = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('sent_at')->orWhere('sent_at', '>=', $cutoff);
            })
            ->get();

        $ids = [];
        $imageMatch = app(ChannelMessageImageMatchService::class);
        foreach ($messages as $message) {
            if (! $this->isInboundProductImage($message)) {
                continue;
            }

            $productId = $imageMatch->topMatchProductId($message);
            if ($productId > 0) {
                $ids[$productId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    public function isWhichProductReply(array $reply): bool
    {
        $compact = $this->compact(($reply['label'] ?? '').' '.($reply['body'] ?? ''));
        if ($compact === '') {
            return false;
        }

        foreach (self::WHICH_PRODUCT_CUES as $cue) {
            $needle = $this->compact($cue);
            if ($needle !== '' && str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function threadHasInboundProductImages(ChannelConversation $conversation): bool
    {
        return $conversation->messages->contains(
            fn (ChannelMessage $message): bool => $this->isInboundProductImage($message)
        );
    }

    /**
     * Inbound catalog photos that staff have not quoted with an outbound reply.
     *
     * @return list<ChannelMessage>
     */
    public function unrepliedInboundProductImages(ChannelConversation $conversation): array
    {
        $repliedIds = [];
        foreach ($conversation->messages as $message) {
            if ($message->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
                continue;
            }
            $replyToId = (int) ($message->reply_to_message_id ?? 0);
            if ($replyToId > 0) {
                $repliedIds[$replyToId] = true;
            }
        }

        $out = [];
        foreach ($this->inboundProductImages($conversation) as $message) {
            if (isset($repliedIds[(int) $message->id])) {
                continue;
            }
            $out[] = $message;
        }

        return $out;
    }

    /**
     * Unreplied inbound photos whose attached catalog match is strictly above 84%.
     *
     * @return list<array{message_id: int, product_id: int, match_percent: float}>
     */
    public function bulkPricedImageTargets(
        ChannelConversation $conversation,
        ChannelMessageImageMatchService $matches,
    ): array {
        $targets = [];
        foreach ($this->unrepliedInboundProductImages($conversation) as $message) {
            $productId = (int) ($message->matched_product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $percent = $matches->matchPercent($message);
            if ($percent <= self::PRICED_IMAGE_BULK_MIN_PERCENT) {
                continue;
            }

            $targets[] = [
                'message_id' => (int) $message->id,
                'product_id' => $productId,
                'match_percent' => $percent,
            ];
        }

        return $targets;
    }

    /**
     * True when this inbound photo is paired with a price ask (caption or nearby text).
     *
     * @param  Collection<int, ChannelMessage>  $threadMessages
     */
    public function shouldHighlightPricedImage(
        ChannelMessage $message,
        Collection $threadMessages,
        array $matches,
    ): bool {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND || ! $message->isImageAttachment()) {
            return false;
        }

        $match = $matches[(int) $message->id] ?? null;
        if (is_array($match) && ($match['intent'] ?? null) === self::INTENT_PRICE) {
            return true;
        }

        $index = $threadMessages->values()->search(
            fn (ChannelMessage $row): bool => (int) $row->id === (int) $message->id
        );
        $nearby = is_int($index) ? $this->nearbyInboundText($threadMessages->values(), $index) : '';
        $blob = trim((string) ($message->body ?? '')."\n".$nearby);

        if ($this->looksLikePriceQuery($blob)) {
            return true;
        }

        foreach ($threadMessages as $row) {
            if ($row->direction !== ChannelMessage::DIRECTION_INBOUND) {
                continue;
            }
            $other = $matches[(int) $row->id] ?? null;
            if (! is_array($other) || ($other['intent'] ?? null) !== self::INTENT_PRICE) {
                continue;
            }
            if ($this->withinMinutes($message, $row, 15)) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
            "\u{200C}" => '', "\u{200D}" => '',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function compact(string $text): string
    {
        return str_replace(' ', '', $this->normalize($text));
    }

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     */
    public function intentForReply(array $reply): ?string
    {
        return $this->detectIntent(($reply['label'] ?? '').' '.($reply['body'] ?? ''));
    }

    /**
     * @param  Collection<int, ChannelMessage>  $inbound
     */
    private function nearbyInboundText(Collection $inbound, int $index): string
    {
        $current = $inbound->get($index);
        if (! $current instanceof ChannelMessage) {
            return '';
        }

        $parts = [];
        foreach ([-1, 1] as $delta) {
            $neighbor = $inbound->get($index + $delta);
            if (! $neighbor instanceof ChannelMessage) {
                continue;
            }
            if (! $this->withinMinutes($current, $neighbor, 15)) {
                continue;
            }
            $body = trim((string) ($neighbor->body ?? ''));
            if ($body !== '') {
                $parts[] = $body;
            }
        }

        return implode("\n", $parts);
    }

    private function withinMinutes(ChannelMessage $a, ChannelMessage $b, int $minutes): bool
    {
        $left = $a->sent_at;
        $right = $b->sent_at;
        if ($left === null || $right === null) {
            return true;
        }

        return abs($left->diffInSeconds($right)) <= ($minutes * 60);
    }

    /**
     * @param  array<string, array{id: string, label: string, body: string}>  $repliesById
     * @return array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}|null
     */
    private function matchExamples(string $text, Collection $examples, array $repliesById): ?array
    {
        $normalized = $this->normalize($text);
        $compact = $this->compact($text);
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($examples as $example) {
            if (! $example instanceof InboxQuickReplyExample) {
                continue;
            }
            $reply = $repliesById[$example->quick_reply_id] ?? null;
            if ($reply === null) {
                continue;
            }

            $sample = (string) $example->example_normalized;
            $sampleCompact = str_replace(' ', '', $sample);
            $score = 0.0;
            if ($sample === $normalized || $sampleCompact === $compact) {
                $score = 1.0;
            } else {
                similar_text($compact, $sampleCompact, $percent);
                $score = $percent / 100;
                $maxLen = max(strlen($compact), strlen($sampleCompact));
                if (
                    $maxLen > 0
                    && $maxLen <= 24
                    && preg_match('/^[\x20-\x7e]+$/', $compact) === 1
                    && preg_match('/^[\x20-\x7e]+$/', $sampleCompact) === 1
                ) {
                    $distance = levenshtein($compact, $sampleCompact);
                    $allowed = $maxLen <= 6 ? 1 : 2;
                    if ($distance <= $allowed) {
                        $score = max($score, 0.86);
                    }
                }
            }

            if ($score < 0.78) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $reply;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'id' => $best['id'],
            'label' => $best['label'],
            'body' => $best['body'],
            'via' => self::VIA_EXAMPLE,
            'intent' => $this->intentForReply($best),
            'tagged' => false,
        ];
    }

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     * @return array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}|null
     */
    private function matchColorGuarantee(string $text, array $replies): ?array
    {
        if (! $this->looksLikeColorGuarantee($text)) {
            return null;
        }

        foreach ($replies as $reply) {
            if (! $this->isColorGuaranteeReply($reply)) {
                continue;
            }

            return [
                'id' => $reply['id'],
                'label' => $reply['label'],
                'body' => $reply['body'],
                'via' => self::VIA_INTENT,
                'intent' => null,
                'tagged' => false,
            ];
        }

        return null;
    }

    /**
     * @param  list<array{id: string, label: string, body: string}>  $replies
     * @return array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}|null
     */
    private function matchIntent(
        string $text,
        array $replies,
        ChannelMessage $message,
        bool $hasInboundProductImages,
    ): ?array {
        $intent = $this->detectIntent($text);
        if ($intent === null) {
            return null;
        }

        foreach ($replies as $reply) {
            if ($this->intentForReply($reply) !== $intent) {
                continue;
            }

            $match = [
                'id' => $reply['id'],
                'label' => $reply['label'],
                'body' => $reply['body'],
                'via' => self::VIA_INTENT,
                'intent' => $intent,
                'tagged' => false,
            ];

            if ($this->shouldSuppressWhichProductMatch($message, $match, $hasInboundProductImages)) {
                continue;
            }

            return $match;
        }

        return null;
    }

    /**
     * Price asks should not suggest "which product?" after the customer already sent photos.
     *
     * @param  array{id: string, label: string, body: string, via: string, intent: ?string, tagged: bool}  $match
     */
    /**
     * Suggest the কালার গ্যারান্টি chip only when a session product is পাহাড়ি আলছড়া.
     * Staff tags are left as-is.
     *
     * @param  array{id?: string, label?: string, body?: string, via?: string, tagged?: bool}  $match
     */
    private function shouldSuppressColorGuaranteeMatch(ChannelConversation $conversation, array $match): bool
    {
        if (($match['via'] ?? '') === self::VIA_TAGGED || ($match['tagged'] ?? false)) {
            return false;
        }

        if (! $this->isColorGuaranteeReply($match)) {
            return false;
        }

        return ! $this->sessionHasPahariAlchoraProduct($conversation);
    }

    private function foldBanglaRra(string $text): string
    {
        return strtr($text, [
            "\u{09DC}" => "\u{09A1}",
            "\u{09DD}" => "\u{09A2}",
            "\u{09BC}" => '',
        ]);
    }

    private function shouldSuppressWhichProductMatch(
        ChannelMessage $message,
        array $match,
        bool $hasInboundProductImages,
    ): bool {
        if (! $hasInboundProductImages || ($match['tagged'] ?? false)) {
            return false;
        }

        if (! $this->isWhichProductReply($match)) {
            return false;
        }

        return $this->looksLikePriceQuery((string) ($message->body ?? ''));
    }

    /**
     * @return list<ChannelMessage>
     */
    private function inboundProductImages(ChannelConversation $conversation): array
    {
        $out = [];
        foreach ($conversation->messages as $message) {
            if ($this->isInboundProductImage($message)) {
                $out[] = $message;
            }
        }

        return $out;
    }

    public function isInboundProductImage(ChannelMessage $message): bool
    {
        return $message->direction === ChannelMessage::DIRECTION_INBOUND
            && $message->isImageAttachment()
            && ! $message->isStickerAttachment();
    }
}
