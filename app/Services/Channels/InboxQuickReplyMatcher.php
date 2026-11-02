<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use Illuminate\Support\Collection;

class InboxQuickReplyMatcher
{
    public const INTENT_PRICE = 'price';

    public const INTENT_DELIVERY = 'delivery';

    public const INTENT_ORDER = 'order';

    public const VIA_TAGGED = 'tagged';

    public const VIA_EXAMPLE = 'example';

    public const VIA_INTENT = 'intent';

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
            'কিভাবে', 'কিভাবেঅর্ডার', 'অর্ডারকরব', 'অর্ডারকরবো', 'কিভাবেনিব', 'কিভাবেঅর্ডারকরব',
            'অর্ডারকিভাবে',
        ],
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

        $out = [];
        foreach ($messages as $message) {
            $match = $this->matchMessage($message, $replies, $examples);
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
        if ($exampleHit !== null) {
            return $exampleHit;
        }

        return $this->matchIntent($haystack, $replies);
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
        return $this->detectIntent($text) === self::INTENT_PRICE;
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
    private function matchIntent(string $text, array $replies): ?array
    {
        $intent = $this->detectIntent($text);
        if ($intent === null) {
            return null;
        }

        foreach ($replies as $reply) {
            if ($this->intentForReply($reply) === $intent) {
                return [
                    'id' => $reply['id'],
                    'label' => $reply['label'],
                    'body' => $reply['body'],
                    'via' => self::VIA_INTENT,
                    'intent' => $intent,
                    'tagged' => false,
                ];
            }
        }

        return null;
    }
}
