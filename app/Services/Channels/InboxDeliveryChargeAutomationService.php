<?php

namespace App\Services\Channels;

use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\City;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxDeliveryChargeAutomationService
{
    public const REPLY_LABEL = 'ডেলিভারি চার্জ ও সময়';

    public const BODY_DHAKA = 'ঢাকা শহর এলাকায় ডেলিভারি চার্জ ৮০ টাকা, সময় ২-৪ দিন।';

    public const BODY_OUTSIDE = 'ঢাকা শহর এলাকার বাহিরে ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিনের মাঝে পাবেন।';

    public const BODY = self::BODY_DHAKA."\n\n".self::BODY_OUTSIDE;

    public const BODY_KOBE_ASK = 'ডেলিভারি এরিয়া কোথায়?';

    public const RAW_FLAG = 'auto_delivery_charge';

    public const RAW_KIND = 'auto_delivery_kind';

    public const KIND_CHARGE = 'charge';

    public const KIND_KOBE_ASK = 'kobe_ask';

    public const KIND_KOBE_DAYS = 'kobe_days';

    public const ZONE_DHAKA = 'dhaka';

    public const ZONE_OUTSIDE = 'outside';

    /**
     * PHP dayOfWeek: 0 = Sunday. Spelling follows the customer-facing copy (মংগলবার).
     *
     * @var array<int, string>
     */
    private const WEEKDAYS = [
        0 => 'রবিবার',
        1 => 'সোমবার',
        2 => 'মংগলবার',
        3 => 'বুধবার',
        4 => 'বৃহস্পতিবার',
        5 => 'শুক্রবার',
        6 => 'শনিবার',
    ];

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
     * Time/charge question wording (“kotodin lagbe” = how many days), never a place.
     *
     * @var list<string>
     */
    private const NON_PLACE_CUES = [
        'kotodin', 'koto din', 'koto', 'din', 'days', 'day',
        'lagbe', 'lagben', 'hoben', 'hobe', 'hobena',
        'time', 'charge', 'cost',
        'কতদিন', 'কত', 'কতো', 'দিন',
        'লাগবে', 'হবেন', 'হবে',
        'সময়', 'সময়', 'চার্জ',
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

        if (InboxOrderProcessAutomationService::willHandle($message)) {
            return;
        }

        if (! app(self::class)->shouldHandle($conversation, $message)) {
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

        if (! $this->shouldHandle($conversation, $message)) {
            return null;
        }

        if (! $conversation->isWithinMessagingWindow()) {
            return null;
        }

        $plan = $this->planReply($conversation, $message);
        if ($plan === null || $plan['body'] === '') {
            return null;
        }

        return InboxPriceQueryAutomationService::withConversationLock(
            (int) $conversation->id,
            function () use ($conversation, $message, $plan): ?ChannelMessage {
                if ($this->alreadyReplied($conversation, $message, $plan['kind'], $plan['body'])) {
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
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND) {
            return false;
        }

        $text = (string) ($message->body ?? '');
        if ($this->matcher->looksLikeKobePabo($text) || $this->matcher->isDeliveryChargeAsk($message)) {
            return true;
        }

        if (! $this->isWaitingForKobeArea($conversation)) {
            return false;
        }

        $hit = $this->locationFromText($text, true);

        return $hit['zone'] !== null || $hit['area'] !== null;
    }

    /**
     * @return array{kind: self::KIND_CHARGE|self::KIND_KOBE_ASK|self::KIND_KOBE_DAYS, body: string}|null
     */
    public function planReply(ChannelConversation $conversation, ChannelMessage $message): ?array
    {
        $text = (string) ($message->body ?? '');
        $isKobe = $this->matcher->looksLikeKobePabo($text);
        $isCharge = $this->matcher->isDeliveryChargeAsk($message) && ! $isKobe;

        if ($isCharge) {
            return [
                'kind' => self::KIND_CHARGE,
                'body' => $this->bodyForLocation($this->locationFor($conversation, $message)),
            ];
        }

        $waiting = $this->isWaitingForKobeArea($conversation);
        if (! $isKobe && ! $waiting) {
            return null;
        }

        $location = $isKobe
            ? $this->locationFor($conversation, $message)
            : $this->locationFromText($text, true);

        if ($location['zone'] !== null || (is_string($location['area']) && $location['area'] !== '')) {
            $zone = $location['zone'] ?? self::ZONE_OUTSIDE;

            return [
                'kind' => self::KIND_KOBE_DAYS,
                'body' => self::weekdayDeliveryBody($zone),
            ];
        }

        if ($isKobe) {
            return [
                'kind' => self::KIND_KOBE_ASK,
                'body' => self::BODY_KOBE_ASK,
            ];
        }

        return null;
    }

    /**
     * Delivery / কবে পাব copy to send with an অর্ডার প্রসেস match.
     *
     * @return array{kind: self::KIND_CHARGE|self::KIND_KOBE_ASK|self::KIND_KOBE_DAYS, body: string}|null
     */
    public function planAlongsideOrder(ChannelConversation $conversation, ChannelMessage $message): ?array
    {
        $text = (string) ($message->body ?? '');
        $isKobe = $this->matcher->looksLikeKobePabo($text);
        $location = $this->locationFor($conversation, $message);
        $hasPlace = $location['zone'] !== null || (is_string($location['area']) && $location['area'] !== '');
        $isCharge = $this->matcher->looksLikeDeliveryCharge($text) || $hasPlace;

        if ($isKobe || $this->isWaitingForKobeArea($conversation)) {
            $kobeLocation = $isKobe ? $location : $this->locationFromText($text, true);
            if ($kobeLocation['zone'] !== null || (is_string($kobeLocation['area']) && $kobeLocation['area'] !== '')) {
                $zone = $kobeLocation['zone'] ?? self::ZONE_OUTSIDE;

                return [
                    'kind' => self::KIND_KOBE_DAYS,
                    'body' => self::weekdayDeliveryBody($zone),
                ];
            }

            if ($isKobe) {
                return [
                    'kind' => self::KIND_KOBE_ASK,
                    'body' => self::BODY_KOBE_ASK,
                ];
            }
        }

        if ($isCharge) {
            return [
                'kind' => self::KIND_CHARGE,
                'body' => $this->bodyForLocation($location),
            ];
        }

        return null;
    }

    /**
     * @param  array{kind: self::KIND_CHARGE|self::KIND_KOBE_ASK|self::KIND_KOBE_DAYS, body: string}|null  $plan
     */
    public function sendAlongsideOrder(ChannelConversation $conversation, ChannelMessage $message, ?array $plan = null): ?ChannelMessage
    {
        if (! $this->settings->enabled(InboxAutomationSettings::DELIVERY_CHARGE)) {
            return null;
        }

        $plan ??= $this->planAlongsideOrder($conversation, $message);
        if ($plan === null || $plan['body'] === '') {
            return null;
        }

        if ($this->alreadyReplied($conversation, $message, $plan['kind'], $plan['body'])) {
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

    public static function personalizedDhakaBody(string $area): string
    {
        return trim($area).' ডেলিভারি চার্জ ৮০ টাকা, ২-৪ দিনের মাঝে পাবেন।';
    }

    public static function personalizedBody(string $area): string
    {
        return trim($area).' ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিন সময় লাগতে পারে, সম্ভব হলে, হোম ডেলিভারি পাবেন।';
    }

    public static function weekdayDeliveryBody(string $zone, ?Carbon $now = null): string
    {
        $now = ($now ?? Carbon::now('Asia/Dhaka'))->copy()->timezone('Asia/Dhaka')->startOfDay();
        $offsets = $zone === self::ZONE_DHAKA ? [3, 4] : [4, 5];
        $first = self::banglaWeekday($now->copy()->addDays($offsets[0]));
        $second = self::banglaWeekday($now->copy()->addDays($offsets[1]));

        return "আজকে অর্ডার করলে {$first} অথবা {$second} ডেলিভারি পাবেন";
    }

    public static function banglaWeekday(Carbon $date): string
    {
        $index = (int) $date->copy()->timezone('Asia/Dhaka')->dayOfWeek;

        return self::WEEKDAYS[$index] ?? '';
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
            if ($this->isConfirmedDhakaCityPlace($area)) {
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
    private function locationFromText(string $text, bool $allowStandalone = false): array
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
        } elseif ($dhakaLen > 0 && $dhakaLen >= $catalogLen && ! $this->isGenericMention($dhaka['display'] ?? $dhaka['cue'] ?? null)) {
            $zone = self::ZONE_DHAKA;
        } elseif ($catalog !== null && ! $this->isGenericMention($catalog['display'] ?? null)) {
            $zone = $catalog['zone'];
        } elseif ($dhakaLen > 0) {
            $zone = self::ZONE_DHAKA;
        } elseif ($catalog !== null) {
            $zone = $catalog['zone'];
        }

        $area = $this->bestMention($outside, $dhaka, $catalog);
        if ($area === null) {
            $area = $this->areaPrefixBeforeDelivery($text);
        }

        if ($area !== null) {
            $zone = $this->isConfirmedDhakaCityPlace($area)
                ? self::ZONE_DHAKA
                : self::ZONE_OUTSIDE;
        } elseif ($zone === null) {
            $dhakaNeedle = $this->compactHaystack('dhaka');
            $dhakaBn = $this->compactHaystack('ঢাকা');
            if (($dhakaNeedle !== '' && str_contains($compact, $dhakaNeedle))
                || ($dhakaBn !== '' && str_contains($compact, $dhakaBn))) {
                $zone = self::ZONE_DHAKA;
            }
        }

        if ($area === null && $zone === null && $allowStandalone) {
            $standalone = $this->standaloneArea($text);
            if ($standalone !== null) {
                return [
                    'zone' => $this->isConfirmedDhakaCityPlace($standalone)
                        ? self::ZONE_DHAKA
                        : self::ZONE_OUTSIDE,
                    'area' => $standalone,
                ];
            }
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

    private function cueMatches(string $text, string $compact, string $cue): bool
    {
        $needle = $this->compactHaystack($cue);
        if ($needle === '') {
            return false;
        }

        $len = mb_strlen($needle);
        if ($len >= 4) {
            return str_contains($compact, $needle);
        }

        if ($len < 3) {
            return false;
        }

        return preg_match('/'.preg_quote($cue, '/').'/iu', $text) === 1;
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
            if (! $this->cueMatches($text, $compact, $cue)) {
                continue;
            }
            $needle = $this->compactHaystack($cue);
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

    /**
     * The 80৳ named-area line is only for a known Dhaka-city thana / 80৳ catalog
     * area. A mention of ঢাকা next to somewhere else must not pull the city rate.
     */
    private function isConfirmedDhakaCityPlace(string $name): bool
    {
        if ($this->isGenericMention($name)) {
            return false;
        }

        $compact = $this->compactHaystack($name);
        if ($compact === '') {
            return false;
        }

        foreach (self::OUTSIDE_CUES as $cue) {
            if ($this->cueMatches($name, $compact, $cue)) {
                return false;
            }
        }

        foreach (self::DHAKA_CITY_CUES as $cue) {
            if ($this->isGenericMention($cue)) {
                continue;
            }
            if ($this->cueMatches($name, $compact, $cue)) {
                return true;
            }
        }

        $areas = Area::query()
            ->active()
            ->with('city:id,name,is_dhaka')
            ->get(['id', 'city_id', 'name', 'unit_type', 'delivery_charge_upto_5', 'aliases']);

        foreach ($areas as $area) {
            if ($this->zoneForArea($area) !== self::ZONE_DHAKA) {
                continue;
            }

            $phrases = array_merge([$area->name], $area->aliasList());
            foreach ($phrases as $phrase) {
                $needle = $this->compactHaystack((string) $phrase);
                if ($needle === '' || mb_strlen($needle) < 4) {
                    continue;
                }
                if ($compact === $needle || str_contains($compact, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isGenericMention(?string $name): bool
    {
        $compact = $this->compactHaystack((string) $name);
        if ($compact === '') {
            return $name === null || trim((string) $name) === '';
        }

        foreach (self::GENERIC_MENTIONS as $generic) {
            $needle = $this->compactHaystack($generic);
            if ($needle !== '' && $compact === $needle) {
                return true;
            }
        }

        return false;
    }

    private function usableMention(?string $name): ?string
    {
        $name = $this->cleanPlaceName((string) $name);
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return null;
        }

        $kept = [];
        foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
            $wordCompact = $this->compactHaystack($word);
            if ($wordCompact === '' || $this->isNonPlacePhrase($wordCompact)) {
                continue;
            }
            $kept[] = $word;
        }

        if ($kept === []) {
            return null;
        }

        $name = $this->cleanPlaceName(implode(' ', $kept));
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [];
        if (count($words) > 4) {
            return null;
        }

        $compact = $this->compactHaystack($name);
        if ($compact === '' || $this->isNonPlacePhrase($compact)) {
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

    private function isNonPlacePhrase(string $compact): bool
    {
        if ($compact === '') {
            return true;
        }

        $needles = [];
        foreach (self::NON_PLACE_CUES as $cue) {
            $needle = $this->compactHaystack($cue);
            if ($needle !== '' && strlen($needle) >= 2) {
                $needles[] = $needle;
            }
        }
        $needles = array_values(array_unique($needles));
        usort($needles, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $remaining = $compact;
        while ($remaining !== '') {
            $hit = false;
            foreach ($needles as $needle) {
                if (str_starts_with($remaining, $needle)) {
                    $remaining = substr($remaining, strlen($needle));
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private function standaloneArea(string $text): ?string
    {
        $name = $this->usableMention($text);
        if ($name === null) {
            return null;
        }

        $compact = $this->compactHaystack($name);
        if (mb_strlen($compact) < 2) {
            return null;
        }

        foreach ([
            'ok', 'okay', 'yes', 'no', 'ha', 'hmm', 'thanks',
            'হবে', 'না', 'জি', 'হ্যাঁ', 'হা', 'হুম', 'আচ্ছা', 'ধন্যবাদ', 'আপু', 'apu',
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

    private function isWaitingForKobeArea(ChannelConversation $conversation): bool
    {
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get() as $row) {
            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }

            $kind = $this->autoKind($row);
            if ($kind === null) {
                continue;
            }

            return $kind === self::KIND_KOBE_ASK;
        }

        return false;
    }

    private function alreadyReplied(ChannelConversation $conversation, ChannelMessage $inbound, string $kind, string $body): bool
    {
        $inboundId = (int) $inbound->id;
        $cutoff = InboxPriceQueryAutomationService::sessionCutoff();

        foreach (ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->get() as $row) {
            if ((int) ($row->reply_to_message_id ?? 0) === $inboundId) {
                if ($this->autoKind($row) !== null || (string) $row->body === $body) {
                    return true;
                }
                if (InboxAutomationSettings::isAutomatedOutbound($row)) {
                    continue;
                }

                return true;
            }

            $sentKind = $this->autoKind($row);
            if ($sentKind === null) {
                continue;
            }

            if ($row->sent_at !== null && $row->sent_at->lt($cutoff)) {
                continue;
            }

            if ((string) $row->body === $body || $sentKind === $kind) {
                return true;
            }
        }

        return false;
    }

    private function autoKind(ChannelMessage $row): ?string
    {
        $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
        $kind = (string) ($payload[self::RAW_KIND] ?? '');
        if ($kind !== '') {
            return $kind;
        }

        $body = (string) $row->body;
        if ($body === self::BODY_KOBE_ASK) {
            return self::KIND_KOBE_ASK;
        }

        if (str_contains($body, 'আজকে অর্ডার করলে') && str_contains($body, 'ডেলিভারি পাবেন')) {
            return self::KIND_KOBE_DAYS;
        }

        $isAuto = ($payload[self::RAW_FLAG] ?? false) === true
            || in_array($body, [self::BODY, self::BODY_DHAKA, self::BODY_OUTSIDE], true);
        if ($isAuto) {
            return self::KIND_CHARGE;
        }

        return null;
    }
}
