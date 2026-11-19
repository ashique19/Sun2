<?php

namespace App\Services\Channels;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\City;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxDeliveryChargeAutomationService
{
    public const REPLY_LABEL = 'ডেলিভারি চার্জ ও সময়';

    public const BODY_DHAKA = 'ঢাকা শহর এলাকায় ৮০ টাকা, সময় ২-৪ দিন।';

    public const BODY_OUTSIDE = 'ঢাকা শহর এলাকার বাহিরে ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিনের মাঝে পাবেন।';

    public const BODY = self::BODY_DHAKA."\n\n".self::BODY_OUTSIDE;

    public const RAW_FLAG = 'auto_delivery_charge';

    public const ZONE_DHAKA = 'dhaka';

    public const ZONE_OUTSIDE = 'outside';

    /**
     * Compacted places that are billed as outside Dhaka city (120৳ / 3–5 days),
     * including Dhaka-district upazilas like Savar and Ashulia.
     *
     * @var list<string>
     */
    private const OUTSIDE_CUES = [
        'savar', 'সাভার',
        'ashulia', 'asulia', 'আশুলিয়া', 'আশুলিয়া',
        'gazipur', 'গাজীপুর',
        'tongi', 'টঙ্গী', 'টঙ্গি',
        'narayanganj', 'narayangonj', 'নারায়ণগঞ্জ', 'নারায়ণগঞ্জ',
        'chittagong', 'chattogram', 'চট্টগ্রাম',
        'khulna', 'খুলনা',
        'rajshahi', 'রাজশাহী',
        'sylhet', 'সিলেট',
        'barisal', 'barishal', 'বরিশাল',
        'rangpur', 'রংপুর',
        'mymensingh', 'ময়মনসিংহ', 'ময়মনসিংহ',
        'cumilla', 'comilla', 'কুমিল্লা',
        'narsingdi', 'নরসিংদী',
        'tangail', 'টাঙ্গাইল',
        'dhakarbaire', 'ঢাকারবাইরে', 'ঢাকারবাহিরে', 'outsidedhaka',
    ];

    /**
     * Compacted Dhaka-city thanas / “inside Dhaka” wording (80৳ / 2–4 days).
     *
     * @var list<string>
     */
    private const DHAKA_CITY_CUES = [
        'mirpur', 'uttara', 'dhanmondi', 'dhanmondhi', 'gulshan', 'banani',
        'motijheel', 'farmgate', 'bashabo', 'bashaboo', 'mohammadpur',
        'wari', 'ramna', 'tejgaon', 'teigaon', 'pallabi', 'badda',
        'baridhara', 'lalbagh', 'kallyanpur', 'kalyanpur', 'shyamoli',
        'shamoli', 'shymoli', 'olddhaka', 'পুরানঢাকা',
        'মিরপুর', 'উত্তরা', 'ধানমন্ডি', 'গুলশান', 'বনানী', 'মতিঝিল',
        'dhakacity', 'ঢাকাশহর', 'insidedhaka', 'ঢাকারভিতরে',
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
        private InboxQuickReplyService $quickReplies,
        private ChannelReplyService $replies,
    ) {}

    public static function scheduleAfterInbound(ChannelConversation $conversation, ChannelMessage $message): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        if (! app(InboxAutomationSettings::class)->enabled(InboxAutomationSettings::DELIVERY_CHARGE)) {
            return;
        }

        if ($message->isImageAttachment() && ! $message->isStickerAttachment()) {
            return;
        }

        if (! app(InboxQuickReplyMatcher::class)->isDeliveryChargeAsk($message)) {
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
                Log::warning('Inbox delivery-charge automation failed.', [
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
        if (! $this->settings->enabled(InboxAutomationSettings::DELIVERY_CHARGE)) {
            return null;
        }

        if (! $this->matcher->isDeliveryChargeAsk($message)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        $body = $this->replyBody($conversation, $message);
        if ($body === '') {
            return null;
        }

        return InboxPriceQueryAutomationService::withConversationLock(
            (int) $conversation->id,
            function () use ($conversation, $message, $body): ?ChannelMessage {
                if ($this->alreadyReplied($conversation, $message, $body)) {
                    return null;
                }

                $result = $this->replies->sendText(
                    $conversation,
                    $body,
                    false,
                    $message,
                );

                $stored = $result['message'] ?? null;
                if (! ($result['ok'] ?? false) || ! $stored instanceof ChannelMessage) {
                    return null;
                }

                $payload = is_array($stored->raw_payload) ? $stored->raw_payload : [];
                $payload[self::RAW_FLAG] = true;
                $stored->forceFill(['raw_payload' => $payload])->save();

                return $stored->fresh();
            },
        );
    }

    public function replyBody(?ChannelConversation $conversation = null, ?ChannelMessage $message = null): string
    {
        if ($conversation instanceof ChannelConversation && $message instanceof ChannelMessage) {
            return $this->bodyForZone($this->zoneFor($conversation, $message));
        }

        return self::BODY;
    }

    /**
     * @return self::ZONE_DHAKA|self::ZONE_OUTSIDE|null
     */
    public function zoneFor(ChannelConversation $conversation, ChannelMessage $inbound): ?string
    {
        $zone = $this->zoneFromHaystack($this->compactHaystack((string) ($inbound->body ?? '')));
        if ($zone !== null) {
            return $zone;
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

            $zone = $this->zoneFromHaystack($this->compactHaystack((string) ($row->body ?? '')));
            if ($zone !== null) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, label: string, body: string}|null
     */
    public function savedReply(): ?array
    {
        foreach ($this->quickReplies->all() as $reply) {
            if ($this->matcher->isDeliveryChargeReply($reply)) {
                return $reply;
            }
        }

        return null;
    }

    /**
     * @param  self::ZONE_DHAKA|self::ZONE_OUTSIDE|null  $zone
     */
    private function bodyForZone(?string $zone): string
    {
        return match ($zone) {
            self::ZONE_DHAKA => self::BODY_DHAKA,
            self::ZONE_OUTSIDE => self::BODY_OUTSIDE,
            default => self::BODY,
        };
    }

    /**
     * @return self::ZONE_DHAKA|self::ZONE_OUTSIDE|null
     */
    private function zoneFromHaystack(string $compact): ?string
    {
        if ($compact === '') {
            return null;
        }

        $outsideLen = $this->longestCueLength($compact, self::OUTSIDE_CUES);
        $dhakaLen = $this->longestCueLength($compact, self::DHAKA_CITY_CUES);

        if ($outsideLen > 0 && $outsideLen >= $dhakaLen) {
            return self::ZONE_OUTSIDE;
        }

        if ($dhakaLen > 0) {
            return self::ZONE_DHAKA;
        }

        $fromCatalog = $this->zoneFromCatalog($compact);
        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        $dhaka = $this->compactHaystack('dhaka');
        $dhakaBn = $this->compactHaystack('ঢাকা');
        if (($dhaka !== '' && str_contains($compact, $dhaka))
            || ($dhakaBn !== '' && str_contains($compact, $dhakaBn))) {
            return self::ZONE_DHAKA;
        }

        return null;
    }

    /**
     * @param  list<string>  $cues
     */
    private function longestCueLength(string $compact, array $cues): int
    {
        $best = 0;
        foreach ($cues as $cue) {
            $needle = $this->compactHaystack($cue);
            if ($needle === '' || mb_strlen($needle) < 4) {
                continue;
            }
            if (! str_contains($compact, $needle)) {
                continue;
            }
            $best = max($best, mb_strlen($needle));
        }

        return $best;
    }

    /**
     * @return self::ZONE_DHAKA|self::ZONE_OUTSIDE|null
     */
    private function zoneFromCatalog(string $compact): ?string
    {
        $bestZone = null;
        $bestLen = 0;

        $areas = Area::query()
            ->active()
            ->with('city:id,name,is_dhaka')
            ->get(['id', 'city_id', 'name', 'unit_type', 'delivery_charge_upto_5', 'aliases']);

        foreach ($areas as $area) {
            $phrases = array_merge([$area->name], $area->aliasList());
            foreach ($phrases as $phrase) {
                $needle = $this->compactHaystack((string) $phrase);
                if ($needle === '' || mb_strlen($needle) < 4 || ! str_contains($compact, $needle)) {
                    continue;
                }
                $len = mb_strlen($needle);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestZone = $this->zoneForArea($area);
                }
            }
        }

        if ($bestZone !== null) {
            return $bestZone;
        }

        foreach (City::query()->active()->get(['id', 'name', 'is_dhaka']) as $city) {
            $needle = $this->compactHaystack((string) $city->name);
            if ($needle === '' || mb_strlen($needle) < 4 || ! str_contains($compact, $needle)) {
                continue;
            }
            $len = mb_strlen($needle);
            if ($len > $bestLen) {
                $bestLen = $len;
                $bestZone = $city->is_dhaka ? self::ZONE_DHAKA : self::ZONE_OUTSIDE;
            }
        }

        return $bestZone;
    }

    private function zoneForArea(Area $area): string
    {
        if ((int) $area->delivery_charge_upto_5 === 80) {
            return self::ZONE_DHAKA;
        }

        if ($area->unit_type === 'thana' && (bool) $area->city?->is_dhaka) {
            return self::ZONE_DHAKA;
        }

        return self::ZONE_OUTSIDE;
    }

    private function compactHaystack(string $text): string
    {
        return strtr($this->matcher->compact($text), [
            "\u{09DF}" => "\u{09AF}",
        ]);
    }

    private function alreadyReplied(ChannelConversation $conversation, ChannelMessage $inbound, string $body): bool
    {
        $inboundId = (int) $inbound->id;
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get() as $row) {
            if ((int) ($row->reply_to_message_id ?? 0) === $inboundId) {
                return true;
            }

            $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
            $isAuto = ($payload[self::RAW_FLAG] ?? false) === true
                || in_array((string) $row->body, [self::BODY, self::BODY_DHAKA, self::BODY_OUTSIDE, $body], true);
            if (! $isAuto) {
                continue;
            }

            if ($row->sent_at === null || $row->sent_at->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return false;
    }
}
