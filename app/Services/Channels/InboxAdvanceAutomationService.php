<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxAdvanceAutomationService
{
    public const REPLY_LABEL = 'এডভান্স';

    public const KIND_ASK = 'ask';

    public const KIND_LISTED = 'listed';

    public const KIND_UNLISTED = 'unlisted';

    public const KIND_COD = 'cod';

    public const RAW_FLAG = 'auto_advance';

    public const RAW_KIND = 'auto_advance_kind';

    public const BODY_ASK_LOCATION = 'ডেলিভারি কোন লোকেশনে হবে?';

    public const AREA_LIST_DISPLAY = 'কুমিল্লা, আলিকদম, থানচি, চিম্বুক, লামা, রামগর, মহালছড়ি, লক্ষিছড়ি, নানিয়ারচর, বিলাইছড়ি, বরকল, জুড়াছড়ি, গুইমারা, নাইক্ষংছড়ি, উখিয়া, টেকনাফ, হোয়াইকং';

    /**
     * Canonical Bangla names and compacted aliases for prepaid-delivery areas.
     *
     * @var array<string, list<string>>
     */
    public const AREAS = [
        'কুমিল্লা' => ['কুমিল্লা', 'cumilla', 'comilla'],
        'আলিকদম' => ['আলিকদম', 'alikadam', 'alikodom'],
        'থানচি' => ['থানচি', 'thanchi'],
        'চিম্বুক' => ['চিম্বুক', 'chimbuk'],
        'লামা' => ['লামা', 'lama'],
        'রামগর' => ['রামগর', 'ramgarh', 'ramgor', 'ramgar'],
        'মহালছড়ি' => ['মহালছড়ি', 'মহালছড়ি', 'mahalchari', 'mahalchhari'],
        'লক্ষিছড়ি' => ['লক্ষিছড়ি', 'লক্ষীছড়ি', 'লক্ষ্মীছড়ি', 'lakshmichari', 'lakkichari', 'lokkhichori'],
        'নানিয়ারচর' => ['নানিয়ারচর', 'নানিয়ারচর', 'naniarchar', 'naniyarchar'],
        'বিলাইছড়ি' => ['বিলাইছড়ি', 'বিলাইছড়ি', 'bilaichari', 'bilaichhari'],
        'বরকল' => ['বরকল', 'barkal'],
        'জুড়াছড়ি' => ['জুড়াছড়ি', 'জুড়াছড়ি', 'jurachari', 'juraichari'],
        'গুইমারা' => ['গুইমারা', 'guimara'],
        'নাইক্ষংছড়ি' => ['নাইক্ষংছড়ি', 'নাইক্ষ্যংছড়ি', 'naikkongchari', 'naikhongchari', 'naikhyongchari'],
        'উখিয়া' => ['উখিয়া', 'উখিয়া', 'ukhia', 'ukhiya'],
        'টেকনাফ' => ['টেকনাফ', 'teknaf'],
        'হোয়াইকং' => ['হোয়াইকং', 'হোয়াইকং', 'whaikong', 'whykong', 'hwaikong'],
    ];

    /**
     * Conversation ids already queued this HTTP request.
     *
     * @var array<int, true>
     */
    private static array $scheduled = [];

    public function __construct(
        private InboxAutomationSettings $settings,
        private InboxQuickReplyMatcher $matcher,
        private ChannelReplyService $replies,
    ) {}

    public static function listedBody(string $area): string
    {
        return trim($area).' ডেলিভারি চার্জ ১২০ টাকা আগে পাঠাতে হবে। প্রডাক্ট হাতে পেয়ে ডেলিভারি বয়কে প্রডাক্টের দাম দিবেন।';
    }

    public static function codRefusalBody(string $area): string
    {
        return 'আপনার কথা বুঝতে পেরেছি এবং আমরা আন্তরিকভাবে দুক্ষিত। '.trim($area)." পুরো ক্যাশ অন ডেলিভারির অন্তর্ভুক্ত নয়।\nএডভান্স ডেলিভারি চার্জ ছাড়া আমরা পাঠাব না।";
    }

    public static function unlistedBody(): string
    {
        return 'নিচের এরিয়াগুলোতে ডেলিভারি চার্জ ১২০ টাকা এডভান্স করতে হবে -'."\n".self::AREA_LIST_DISPLAY."।\n\n".'এছাড়া অন্য কোথাও এডভান্স লাগবে না।';
    }

    public static function scheduleAfterInbound(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::ADVANCE)) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (InboxOrderProcessAutomationService::willHandle($message)) {
            return;
        }

        $service = app(self::class);
        if (! $service->shouldHandle($conversation, $message)) {
            return;
        }

        $conversationId = (int) $conversation->id;
        $messageId = (int) $message->id;
        if ($conversationId <= 0 || $messageId <= 0 || isset(self::$scheduled[$conversationId])) {
            return;
        }

        if (self::$scheduled === []) {
            app()->terminating(static function (): void {
                self::$scheduled = [];
            });
        }

        self::$scheduled[$conversationId] = true;

        dispatch(function () use ($conversationId, $messageId): void {
            try {
                app(self::class)->replyFromIds($conversationId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Inbox advance automation failed.', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    public function replyFromIds(int $conversationId, int $messageId): ?ChannelMessage
    {
        $conversation = ChannelConversation::query()->find($conversationId);
        $message = ChannelMessage::query()->find($messageId);
        if (! $conversation || ! $message) {
            return null;
        }

        return $this->reply($conversation, $message);
    }

    public function reply(ChannelConversation $conversation, ChannelMessage $message): ?ChannelMessage
    {
        if (! $this->settings->enabled(InboxAutomationSettings::ADVANCE)) {
            return null;
        }

        if (! $this->shouldHandle($conversation, $message)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        $plan = $this->planReply($conversation, $message);
        if ($plan === null) {
            return null;
        }

        return InboxPriceQueryAutomationService::withConversationLock(
            (int) $conversation->id,
            function () use ($conversation, $message, $plan): ?ChannelMessage {
                if ($this->alreadySent($conversation, $message, $plan['kind'], $plan['body'])) {
                    return null;
                }

                $result = $this->replies->sendText(
                    $conversation,
                    $plan['body'],
                    false,
                    $message,
                );

                $stored = $result['message'] ?? null;
                if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
                    return null;
                }

                $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
                $payload[self::RAW_FLAG] = true;
                $payload[self::RAW_KIND] = $plan['kind'];
                $stored->forceFill(['raw_payload' => $payload])->save();

                return $stored->fresh();
            },
        );
    }

    public function shouldHandle(ChannelConversation $conversation, ChannelMessage $message): bool
    {
        if ($this->matcher->isAdvanceAsk($message) || $this->matcher->looksLikeCodPush((string) ($message->body ?? ''))) {
            return true;
        }

        return $this->isWaitingForLocation($conversation)
            && $this->extractArea((string) ($message->body ?? ''), true) !== null;
    }

    /**
     * @return array{kind: self::KIND_ASK|self::KIND_LISTED|self::KIND_UNLISTED|self::KIND_COD, body: string}|null
     */
    public function planReply(ChannelConversation $conversation, ChannelMessage $message): ?array
    {
        $text = trim((string) ($message->body ?? ''));
        $listed = $this->listedAreaFromSession($conversation, $message);
        $waiting = $this->isWaitingForLocation($conversation);
        $area = $this->extractArea($text, $waiting) ?? $this->sessionAreaDisplay($conversation, $message);
        $pressingCod = $this->matcher->looksLikeCodPush($text);

        if ($pressingCod && is_string($listed) && $listed !== '') {
            return [
                'kind' => self::KIND_COD,
                'body' => self::codRefusalBody($listed),
            ];
        }

        if ($listed !== null) {
            return [
                'kind' => self::KIND_LISTED,
                'body' => self::listedBody($listed),
            ];
        }

        if ($area !== null) {
            return [
                'kind' => self::KIND_UNLISTED,
                'body' => self::unlistedBody(),
            ];
        }

        if ($this->matcher->isAdvanceAsk($message) || $pressingCod) {
            return [
                'kind' => self::KIND_ASK,
                'body' => self::BODY_ASK_LOCATION,
            ];
        }

        return null;
    }

    /**
     * Advance copy to send with an অর্ডার প্রসেস match.
     *
     * @return array{kind: self::KIND_ASK|self::KIND_LISTED|self::KIND_UNLISTED|self::KIND_COD, body: string}|null
     */
    public function planAlongsideOrder(ChannelConversation $conversation, ChannelMessage $message): ?array
    {
        $text = trim((string) ($message->body ?? ''));
        $listed = $this->listedAreaFromSession($conversation, $message);
        $pressingCod = $this->matcher->looksLikeCodPush($text);

        if ($pressingCod && is_string($listed) && $listed !== '') {
            return [
                'kind' => self::KIND_COD,
                'body' => self::codRefusalBody($listed),
            ];
        }

        if ($listed !== null) {
            return [
                'kind' => self::KIND_LISTED,
                'body' => self::listedBody($listed),
            ];
        }

        if ($this->matcher->looksLikeAdvance($text) || $pressingCod) {
            return [
                'kind' => self::KIND_ASK,
                'body' => self::BODY_ASK_LOCATION,
            ];
        }

        return null;
    }

    /**
     * @param  array{kind: self::KIND_ASK|self::KIND_LISTED|self::KIND_UNLISTED|self::KIND_COD, body: string}|null  $plan
     */
    public function sendAlongsideOrder(ChannelConversation $conversation, ChannelMessage $message, ?array $plan = null): ?ChannelMessage
    {
        if (! $this->settings->enabled(InboxAutomationSettings::ADVANCE)) {
            return null;
        }

        $plan ??= $this->planAlongsideOrder($conversation, $message);
        if ($plan === null) {
            return null;
        }

        if ($this->alreadySent($conversation, $message, $plan['kind'], $plan['body'])) {
            return null;
        }

        $result = $this->replies->sendText(
            $conversation,
            $plan['body'],
            false,
            $message,
        );

        $stored = $result['message'] ?? null;
        if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
            return null;
        }

        $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
        $payload[self::RAW_FLAG] = true;
        $payload[self::RAW_KIND] = $plan['kind'];
        $stored->forceFill(['raw_payload' => $payload])->save();

        return $stored->fresh();
    }

    public function listedAreaFromSession(ChannelConversation $conversation, ChannelMessage $inbound): ?string
    {
        $hit = $this->listedAreaHit((string) ($inbound->body ?? ''));
        if ($hit !== null) {
            return $hit;
        }

        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $others = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->where('id', '!=', $inbound->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        foreach ($others as $row) {
            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }
            $hit = $this->listedAreaHit((string) ($row->body ?? ''));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function extractArea(string $text, bool $allowStandalone = false): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $listed = $this->listedAreaHit($text);
        if ($listed !== null) {
            return $listed;
        }

        $prefix = $this->areaPrefix($text);
        if ($prefix !== null) {
            return $prefix;
        }

        if (! $allowStandalone) {
            return null;
        }

        return $this->standalonePlaceName($text);
    }

    public function listedAreaHit(string $text): ?string
    {
        $compact = $this->compactHaystack($text);
        if ($compact === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach (self::AREAS as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $needle = $this->compactHaystack($alias);
                if ($needle === '' || ! str_contains($compact, $needle)) {
                    continue;
                }
                $len = mb_strlen($needle);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $this->displaySlice($text, $alias) ?? $canonical;
                }
            }
        }

        return $best;
    }

    private function sessionAreaDisplay(ChannelConversation $conversation, ChannelMessage $inbound): ?string
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $others = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->where('id', '!=', $inbound->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        foreach ($others as $row) {
            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }
            $area = $this->extractArea((string) ($row->body ?? ''));
            if ($area !== null) {
                return $area;
            }
        }

        return null;
    }

    private function isWaitingForLocation(ChannelConversation $conversation): bool
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();
        $asked = false;

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get() as $row) {
            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            $kind = (string) ($payload[self::RAW_KIND] ?? '');
            $isAuto = ($payload[self::RAW_FLAG] ?? false) === true
                || (string) $row->body === self::BODY_ASK_LOCATION
                || (string) $row->body === self::unlistedBody();
            if (! $isAuto) {
                continue;
            }

            if ($kind === self::KIND_LISTED || $kind === self::KIND_UNLISTED || $kind === self::KIND_COD) {
                return false;
            }

            if ($kind === self::KIND_ASK || (string) $row->body === self::BODY_ASK_LOCATION) {
                $asked = true;
            }
        }

        return $asked;
    }

    private function alreadySent(ChannelConversation $conversation, ChannelMessage $inbound, string $kind, string $body): bool
    {
        $inboundId = (int) $inbound->id;
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get() as $row) {
            if ((int) ($row->reply_to_message_id ?? 0) === $inboundId) {
                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
                $own = ($payload[self::RAW_FLAG] ?? false) === true
                    || (string) $row->body === $body;
                if ($own) {
                    return true;
                }
                if (InboxAutomationSettings::isAutomatedOutbound($row)) {
                    continue;
                }

                return true;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            $isAuto = ($payload[self::RAW_FLAG] ?? false) === true
                || in_array((string) $row->body, [self::BODY_ASK_LOCATION, self::unlistedBody(), $body], true);
            if (! $isAuto) {
                continue;
            }

            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }

            if ((string) $row->body === $body) {
                return true;
            }

            $sentKind = (string) ($payload[self::RAW_KIND] ?? '');
            if ($sentKind === $kind) {
                return true;
            }

            if ($kind === self::KIND_ASK && $sentKind !== '') {
                return true;
            }
        }

        return false;
    }

    private function areaPrefix(string $text): ?string
    {
        if (! preg_match('/(?:এডভান্স|অ্যাডভান্স|অ্যাডভান্সড|advance|advence|ডেলিভারি|ডেলিভারী|ডেলিবারি|delivery|delivary|dilevery)/iu', $text)) {
            return null;
        }

        $parts = preg_split('/(?:এডভান্স|অ্যাডভান্স|অ্যাডভান্সড|advance|advence|ডেলিভারি|ডেলিভারী|ডেলিবারি|delivery|delivary|dilevery)/iu', $text, 2);
        $prefix = $this->cleanPlaceName((string) ($parts[0] ?? ''));
        if (str_contains($prefix, ',')) {
            $prefix = $this->cleanPlaceName((string) (preg_split('/\s*,\s*/u', $prefix)[0] ?? ''));
        }

        return $this->usablePlaceName($prefix);
    }

    private function standalonePlaceName(string $text): ?string
    {
        $name = $this->cleanPlaceName($text);
        if ($this->usablePlaceName($name) === null) {
            return null;
        }

        $compact = $this->compactHaystack($name);
        $full = $this->compactHaystack($text);
        if ($compact === '' || $full === '' || mb_strlen($compact) < 2) {
            return null;
        }

        if (mb_strlen($full) > mb_strlen($compact) + 12) {
            return null;
        }

        return $name;
    }

    private function usablePlaceName(?string $name): ?string
    {
        $name = $this->cleanPlaceName((string) $name);
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [];
        if (count($words) > 4) {
            return null;
        }

        $compact = $this->compactHaystack($name);
        if ($compact === '') {
            return null;
        }

        foreach ([
            'ok', 'okay', 'yes', 'no', 'ha', 'hmm', 'thanks',
            'হবে', 'না', 'জি', 'হ্যাঁ', 'হা', 'হুম', 'আচ্ছা', 'ধন্যবাদ', 'আপু', 'apu',
            'advance', 'এডভান্স', 'অ্যাডভান্স', 'cod', 'ক্যাশ', 'লাগবে',
        ] as $filler) {
            $needle = $this->compactHaystack($filler);
            if ($needle !== '' && $compact === $needle) {
                return null;
            }
        }

        return $name;
    }

    private function cleanPlaceName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}?؟]+$/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+(e|te|ey|ye|a)\s*$/iu', '', $name) ?? $name;
        $name = preg_replace('/\s*(এ|তে|য়ে|য়ে|য়)\s*$/u', '', $name) ?? $name;

        return trim($name);
    }

    private function displaySlice(string $text, string $phrase): ?string
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return null;
        }

        if (preg_match('/'.preg_quote($phrase, '/').'/iu', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function compactHaystack(string $text): string
    {
        return strtr($this->matcher->compact($text), [
            "\u{09DF}" => "\u{09AF}",
        ]);
    }
}
