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

    public const BODY_DHAKA = 'ঢাকা শহর এলাকায় ডেলিভারি চার্জ ৮০ টাকা, সময় ২-৪ দিন।';

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
        'মিরপুর', 'মোহাম্মদপুর', 'উত্তরা', 'ধানমন্ডি', 'গুলশান', 'বনানী', 'মতিঝিল',
        'dhakacity', 'ঢাকাশহর', 'insidedhaka', 'ঢাকারভিতরে',
    ];

    /**
     * Zone labels that should not be used as the customer’s area name.
     *
     * @var list<string>
     */
    private const GENERIC_MENTIONS = [
        'dhaka', 'ঢাকা', 'dhakacity', 'ঢাকাশহর', 'insidedhaka', 'ঢাকারভিতরে',
        'dhakarbaire', 'ঢাকারবাইরে', 'ঢাকারবাহিরে', 'outsidedhaka',
        'olddhaka', 'পুরানঢাকা',
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

    public static function personalizedDhakaBody(string $area): string
    {
        return trim($area).' ডেলিভারি চার্জ ৮০ টাকা, ২-৪ দিনের মাঝে পাবেন।';
    }

    public static function personalizedBody(string $area): string
    {
        return trim($area).' ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিন সময় লাগতে পারে, সম্ভব হলে, হোম ডেলিভারি পাবেন।';
    }

    public function replyBody(?ChannelConversation $conversation = null, ?ChannelMessage $message = null): string
    {
        if ($conversation instanceof ChannelConversation && $message instanceof ChannelMessage) {
            return $this->bodyForLocation($this->locationFor($conversation, $message));
        }

        return self::BODY;
    }

    /**
     * @return self::ZONE_DHAKA|self::ZONE_OUTSIDE|null
     */
    public function zoneFor(ChannelConversation $conversation, ChannelMessage $inbound): ?string
    {
        return $this->locationFor($conversation, $inbound)['zone'];
    }

    /**
     * @return array{zone: self::ZONE_DHAKA|self::ZONE_OUTSIDE|null, area: ?string}
     */
    public function locationFor(ChannelConversation $conversation, ChannelMessage $inbound): array
    {
        $hit = $this->locationFromText((string) ($inbound->body ?? ''));
        if ($hit['zone'] !== null || $hit['area'] !== null) {
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

            $hit = $this->locationFromText((string) ($row->body ?? ''));
            if ($hit['zone'] !== null || $hit['area'] !== null) {
                return $hit;
            }
        }

        return ['zone' => null, 'area' => null];
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
     * @param  array{zone: self::ZONE_DHAKA|self::ZONE_OUTSIDE|null, area: ?string}  $location
     */
    private function bodyForLocation(array $location): string
    {
        $area = $location['area'];
        $zone = $location['zone'];

        if (is_string($area) && $area !== '') {
            if ($zone === self::ZONE_DHAKA) {
                return self::personalizedDhakaBody($area);
            }

            return self::personalizedBody($area);
        }

        return match ($zone) {
            self::ZONE_DHAKA => self::BODY_DHAKA,
            self::ZONE_OUTSIDE => self::BODY_OUTSIDE,
            default => self::BODY,
        };
    }

    /**
     * @return array{zone: self::ZONE_DHAKA|self::ZONE_OUTSIDE|null, area: ?string}
     */
    private function locationFromText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['zone' => null, 'area' => null];
        }

        $compact = $this->compactHaystack($text);
        $outside = $this->bestCue($text, $compact, self::OUTSIDE_CUES);
        $dhaka = $this->bestCue($text, $compact, self::DHAKA_CITY_CUES);
        $catalog = $this->bestCatalogHit($text, $compact);

        $zone = null;
        $outsideLen = $outside['len'] ?? 0;
        $dhakaLen = $dhaka['len'] ?? 0;
        $catalogLen = $catalog['len'] ?? 0;

        if ($outsideLen > 0 && $outsideLen >= $dhakaLen && $outsideLen >= $catalogLen) {
            $zone = self::ZONE_OUTSIDE;
        } elseif ($dhakaLen > 0 && $dhakaLen >= $catalogLen) {
            $zone = self::ZONE_DHAKA;
        } elseif ($catalog !== null) {
            $zone = $catalog['zone'];
        } else {
            $dhakaNeedle = $this->compactHaystack('dhaka');
            $dhakaBn = $this->compactHaystack('ঢাকা');
            if (($dhakaNeedle !== '' && str_contains($compact, $dhakaNeedle))
                || ($dhakaBn !== '' && str_contains($compact, $dhakaBn))) {
                $zone = self::ZONE_DHAKA;
            }
        }

        $area = $this->bestMention($outside, $dhaka, $catalog);
        if ($area === null) {
            $area = $this->areaPrefixBeforeDelivery($text);
        }

        if ($area !== null && $zone === null) {
            $zone = self::ZONE_OUTSIDE;
        }

        return ['zone' => $zone, 'area' => $area];
    }

    /**
     * @param  array{cue: string, len: int, display: ?string}|null  $outside
     * @param  array{cue: string, len: int, display: ?string}|null  $dhaka
     * @param  array{zone: string, len: int, display: ?string}|null  $catalog
     */
    private function bestMention(?array $outside, ?array $dhaka, ?array $catalog): ?string
    {
        $candidates = [];
        foreach ([$outside, $dhaka, $catalog] as $hit) {
            if ($hit === null) {
                continue;
            }
            $display = $this->usableMention($hit['display'] ?? null);
            if ($display === null) {
                continue;
            }
            $candidates[] = ['len' => $hit['len'], 'display' => $display];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['len'] <=> $a['len']);

        return $candidates[0]['display'];
    }

    /**
     * @param  list<string>  $cues
     * @return array{cue: string, len: int, display: ?string}|null
     */
    private function bestCue(string $text, string $compact, array $cues): ?array
    {
        $best = null;
        $bestLen = 0;
        foreach ($cues as $cue) {
            $needle = $this->compactHaystack($cue);
            if ($needle === '' || mb_strlen($needle) < 4 || ! str_contains($compact, $needle)) {
                continue;
            }
            $len = mb_strlen($needle);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = [
                    'cue' => $cue,
                    'len' => $len,
                    'display' => $this->displaySlice($text, $cue),
                ];
            }
        }

        return $best;
    }

    /**
     * @return array{zone: self::ZONE_DHAKA|self::ZONE_OUTSIDE, len: int, display: ?string}|null
     */
    private function bestCatalogHit(string $text, string $compact): ?array
    {
        $best = null;
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
                    $best = [
                        'zone' => $this->zoneForArea($area),
                        'len' => $len,
                        'display' => $this->displaySlice($text, (string) $phrase) ?? (string) $area->name,
                    ];
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach (City::query()->active()->get(['id', 'name', 'is_dhaka']) as $city) {
            $needle = $this->compactHaystack((string) $city->name);
            if ($needle === '' || mb_strlen($needle) < 4 || ! str_contains($compact, $needle)) {
                continue;
            }
            $len = mb_strlen($needle);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = [
                    'zone' => $city->is_dhaka ? self::ZONE_DHAKA : self::ZONE_OUTSIDE,
                    'len' => $len,
                    'display' => $this->displaySlice($text, (string) $city->name) ?? (string) $city->name,
                ];
            }
        }

        return $best;
    }

    private function areaPrefixBeforeDelivery(string $text): ?string
    {
        if (! preg_match('/(?:ডেলিভারি|ডেলিভারী|ডেলিবারি|delivery|delivary|dilevery)/iu', $text)) {
            return null;
        }

        $parts = preg_split('/(?:ডেলিভারি|ডেলিভারী|ডেলিবারি|delivery|delivary|dilevery)/iu', $text, 2);
        $prefix = $this->cleanPlaceName((string) ($parts[0] ?? ''));
        if (str_contains($prefix, ',')) {
            $prefix = $this->cleanPlaceName((string) (preg_split('/\s*,\s*/u', $prefix)[0] ?? ''));
        }

        return $this->usableMention($prefix);
    }

    private function usableMention(?string $name): ?string
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

        foreach (self::GENERIC_MENTIONS as $generic) {
            $needle = $this->compactHaystack($generic);
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
