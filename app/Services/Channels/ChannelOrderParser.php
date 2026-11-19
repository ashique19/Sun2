<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Storefront\AddressLocationGuesser;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelOrderParser
{
    /**
     * Stable codes recorded on drafts so we can improve weak spots later.
     */
    public const WEAK_PHONE_MISSING = 'phone_missing';

    public const WEAK_NAME_MISSING = 'name_missing';

    public const WEAK_NAME_HEURISTIC = 'name_heuristic';

    public const WEAK_ADDRESS_MISSING = 'address_missing';

    public const WEAK_ADDRESS_UNRESOLVED = 'address_unresolved_location';

    public const WEAK_PRODUCT_MISSING = 'product_missing';

    public const WEAK_PRODUCT_NAME_FUZZY = 'product_name_fuzzy';

    public const WEAK_IMAGE_NONE = 'product_image_none';

    public const WEAK_IMAGE_DOWNLOAD_FAILED = 'product_image_download_failed';

    public const WEAK_IMAGE_NO_AUTO_MATCH = 'product_image_no_auto_match';

    public const WEAK_IMAGE_BELOW_AUTO = 'product_image_below_auto_threshold';

    public const WEAK_CATALOG_UNHASHED = 'product_catalog_unhashed';

    public const WEAK_GEMINI_GAP_FILL = 'gemini_gap_fill_used';

    public const WEAK_GEMINI_FAILED = 'gemini_gap_fill_failed';

    public const WEAK_CHATTY_TRANSCRIPT = 'chatty_transcript_discarded';

    public function __construct(
        private GeminiClient $gemini,
        private AddressLocationGuesser $locationGuesser,
        private ChannelInboundMediaService $inboundMedia,
        private ChannelMessageImageMatchService $imageMatches,
        private InboxQuickReplyMatcher $matcher,
    ) {}

    /**
     * @return array{
     *     name:?string,
     *     phone:?string,
     *     address:?string,
     *     city:?string,
     *     area:?string,
     *     cityId:?int,
     *     areaId:?int,
     *     product_id:?int,
     *     product_name:?string,
     *     products: list<array{product_id:int,product_name:string,quantity:int}>,
     *     quantity:int,
     *     missing: list<string>,
     *     weak_points: list<string>,
     *     source: string,
     *     confidence: float,
     *     raw_text: string,
     *     image_matches: list<array{product_id:int,name:string,match_percent:float}>
     * }
     */
    public function parseConversation(ChannelConversation $conversation): array
    {
        $conversation->loadMissing(['messages.replyTo']);

        $inbound = $this->recentInboundMessages($conversation);
        $orderWindow = $this->orderWindowMessages($inbound);
        $rawText = $this->joinMessageText($orderWindow);
        $fullRecentText = $this->joinMessageText($inbound);

        $result = $this->emptyResult($rawText !== '' ? $rawText : $fullRecentText);
        $weak = [];

        $phone = PhoneNumber::extractFirstBangladeshMobile($fullRecentText);
        if ($phone === null) {
            $result['missing'] = ['name', 'phone', 'address', 'product'];
            $result['weak_points'] = [self::WEAK_PHONE_MISSING];

            return $result;
        }

        $result['phone'] = PhoneNumber::display($phone);
        $result['source'] = 'local';

        // Prefer the tighter order window once we know a phone exists.
        if ($rawText === '') {
            $rawText = $fullRecentText;
            $result['raw_text'] = $rawText;
            $orderWindow = $inbound;
        }

        $local = $this->extractLocalFields($rawText, $result['phone']);
        $result = $this->merge($result, $local['fields']);
        $weak = array_merge($weak, $local['weak_points']);

        if ((! $result['cityId'] || ! $result['areaId']) && filled($result['address'])) {
            $guess = $this->locationGuesser->guess((string) $result['address']);
            if ($guess) {
                $result['cityId'] = $result['cityId'] ?: $guess['city_id'];
                $result['areaId'] = $result['areaId'] ?: $guess['area_id'];
                if (! $result['city'] || ! $result['area']) {
                    [$areaLabel, $cityLabel] = array_pad(explode(',', $guess['label'], 2), 2, null);
                    $result['area'] = $result['area'] ?: trim((string) $areaLabel);
                    $result['city'] = $result['city'] ?: trim((string) $cityLabel);
                }
            } else {
                $weak[] = self::WEAK_ADDRESS_UNRESOLVED;
            }
        }

        $imageMatch = $this->matchProductFromImages($inbound);
        $result['image_matches'] = $imageMatch['candidates'];
        $weak = array_merge($weak, $imageMatch['weak_points']);

        $lastPriceAskAt = $this->lastPriceAskAt($inbound);
        $productLines = $this->orderedProductLines($inbound, $orderWindow, $lastPriceAskAt);
        $fromImage = $productLines !== [];
        if ($productLines === []) {
            $productLines = $this->imageProductLinesInWindow(
                $imageMatch['auto_hits'] ?? [],
                $orderWindow,
                $lastPriceAskAt,
            );
            $fromImage = $productLines !== [];
        }
        if ($productLines === []) {
            $nameMatch = $this->matchProductFromText($rawText);
            $weak = array_merge($weak, $nameMatch['weak_points']);
            if ($nameMatch['product_id']) {
                $productLines = [[
                    'product_id' => (int) $nameMatch['product_id'],
                    'product_name' => (string) $nameMatch['product_name'],
                    'quantity' => max(1, (int) $nameMatch['quantity']),
                ]];
            }
        }

        if ($productLines !== []) {
            $result['products'] = $productLines;
            $result['product_id'] = $productLines[0]['product_id'];
            $result['product_name'] = $productLines[0]['product_name'];
            $result['quantity'] = $productLines[0]['quantity'];
            if ($fromImage) {
                $result['source'] = 'local+image';
            }
        }

        // Optional Gemini: text-only gap fill. Never send image bytes (50-photo albums timed out).
        if ($this->gemini->isConfigured() && $this->needsGapFill($result) && trim($rawText) !== '') {
            try {
                $gap = $this->parseGapsWithGemini($rawText, $result);
                $before = $result;
                $result = $this->mergeGapsOnly($result, $gap, $weak);
                if ($result !== $before) {
                    $weak[] = self::WEAK_GEMINI_GAP_FILL;
                    // Prefer Gemini name over a weak local heuristic guess.
                    $weak = array_values(array_filter(
                        $weak,
                        fn (string $code) => $code !== self::WEAK_NAME_HEURISTIC || ! filled($result['name']),
                    ));
                    if (filled($gap['name'] ?? null)) {
                        $weak = array_values(array_diff($weak, [self::WEAK_NAME_HEURISTIC, self::WEAK_NAME_MISSING]));
                    }
                    $result['source'] = str_contains((string) $result['source'], 'image')
                        ? 'local+image+gemini'
                        : 'local+gemini';
                }
            } catch (Throwable $e) {
                Log::warning('Channel order Gemini gap-fill failed.', [
                    'conversation_id' => $conversation->id,
                    'message' => $e->getMessage(),
                ]);
                $weak[] = self::WEAK_GEMINI_FAILED;
            }
        }

        if (! filled($result['name'])) {
            $weak[] = self::WEAK_NAME_MISSING;
        }
        if (! filled($result['address'])) {
            $weak[] = self::WEAK_ADDRESS_MISSING;
        }
        if (! $result['product_id'] && ! filled($result['product_name'])) {
            $weak[] = self::WEAK_PRODUCT_MISSING;
        }

        $result['quantity'] = max(1, (int) ($result['quantity'] ?? 1));
        $result['missing'] = $this->missingFields($result);
        $result['weak_points'] = array_values(array_unique($weak));
        $result['confidence'] = $this->confidence($result, $result['weak_points']);
        $result['raw_text'] = $rawText;

        return $result;
    }

    public function textHasNameCue(string $text): bool
    {
        foreach ($this->textLines($text) as $line) {
            if ($this->labeledNameValue($line) !== null || $this->looksLikeName($line)) {
                return true;
            }
        }

        return false;
    }

    public function textHasAddressCue(string $text): bool
    {
        foreach ($this->textLines($text) as $line) {
            if ($this->labeledLocationValue($line) !== null
                || $this->looksLikeAddress($line)
                || $this->looksLikeLocationOnly($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function textLines(string $text): array
    {
        $lines = preg_split('/\R+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    /**
     * @return Collection<int, ChannelMessage>
     */
    private function recentInboundMessages(ChannelConversation $conversation): Collection
    {
        $lookbackHours = max(1, (int) config('channels.ai_draft.lookback_hours', 48));
        $maxMessages = max(1, (int) config('channels.ai_draft.max_inbound_messages', 15));
        $since = Carbon::now()->subHours($lookbackHours);

        return $conversation->messages
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->filter(function (ChannelMessage $message) use ($since) {
                $sentAt = $message->sent_at;

                if (! $sentAt instanceof Carbon) {
                    return false;
                }

                return $sentAt->greaterThanOrEqualTo($since);
            })
            ->sortBy(fn (ChannelMessage $message) => $message->sent_at?->getTimestamp() ?? 0)
            ->values()
            ->take(-$maxMessages)
            ->values();
    }

    /**
     * Prefer messages from the first phone-bearing inbound through the latest.
     *
     * @param  Collection<int, ChannelMessage>  $inbound
     * @return Collection<int, ChannelMessage>
     */
    private function orderWindowMessages(Collection $inbound): Collection
    {
        if ($inbound->isEmpty()) {
            return $inbound;
        }

        $phoneIndex = null;
        foreach ($inbound->values() as $index => $message) {
            $body = trim((string) ($message->body ?? ''));
            if ($body !== '' && PhoneNumber::extractFirstBangladeshMobile($body)) {
                $phoneIndex = $index;
                break;
            }
        }

        if ($phoneIndex === null) {
            return $inbound;
        }

        // Customers often send name, then area lines, then phone as separate bubbles.
        $start = max(0, $phoneIndex - 8);

        return $inbound->values()->slice($start)->values();
    }

    /**
     * @param  Collection<int, ChannelMessage>  $messages
     */
    private function joinMessageText(Collection $messages): string
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

    /**
     * @return array{fields: array<string, mixed>, weak_points: list<string>}
     */
    private function extractLocalFields(string $rawText, string $phone): array
    {
        $weak = [];
        $lines = preg_split('/\R+/u', $rawText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));

        $phoneNorm = PhoneNumber::normalize($phone);
        $extractedName = $this->extractCustomerName($lines, $phoneNorm);
        $name = $extractedName['name'];
        if ($name !== null && $extractedName['heuristic']) {
            $weak[] = self::WEAK_NAME_HEURISTIC;
        }

        $extractedAddress = $this->extractCustomerAddress($lines, $phoneNorm, $name);
        $discardedNoise = 0;

        foreach ($lines as $line) {
            $linePhone = PhoneNumber::extractFirstBangladeshMobile($line);
            if ($linePhone && PhoneNumber::normalize($linePhone) === $phoneNorm) {
                continue;
            }

            if ($this->looksLikeNoise($line) || $this->looksLikeOrderChatter($line)) {
                $discardedNoise++;
            }
        }

        if ($discardedNoise >= 3) {
            $weak[] = self::WEAK_CHATTY_TRANSCRIPT;
        }

        $address = $extractedAddress['address'];
        if ($address !== null && mb_strlen($address) > 255) {
            $address = mb_substr($address, 0, 255);
        }

        return [
            'fields' => [
                'name' => $name,
                'address' => $address,
                'city' => $extractedAddress['city'],
                'area' => $extractedAddress['area'],
            ],
            'weak_points' => $weak,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{name:?string, heuristic: bool}
     */
    private function extractCustomerName(array $lines, string $phoneNorm): array
    {
        foreach ($lines as $line) {
            $labeled = $this->labeledNameValue($line);
            if ($labeled !== null) {
                return ['name' => $labeled, 'heuristic' => false];
            }
        }

        $phoneIndex = null;
        foreach ($lines as $index => $line) {
            $linePhone = PhoneNumber::extractFirstBangladeshMobile($line);
            if ($linePhone && PhoneNumber::normalize($linePhone) === $phoneNorm) {
                $phoneIndex = $index;
                break;
            }
        }

        $scanFrom = $phoneIndex ?? count($lines);
        for ($index = $scanFrom - 1; $index >= 0; $index--) {
            $line = $lines[$index];
            $linePhone = PhoneNumber::extractFirstBangladeshMobile($line);
            if ($linePhone && PhoneNumber::normalize($linePhone) === $phoneNorm) {
                continue;
            }

            if ($this->looksLikeNoise($line) || $this->looksLikeOrderChatter($line)) {
                continue;
            }

            if ($this->looksLikeName($line)) {
                return ['name' => $this->cleanName($line), 'heuristic' => true];
            }

            $head = $this->leadingNameFromAddress($line);
            if ($head !== null) {
                return ['name' => $head, 'heuristic' => true];
            }
        }

        return ['name' => null, 'heuristic' => false];
    }

    /**
     * @param  list<string>  $lines
     * @return array{address:?string, city:?string, area:?string}
     */
    private function extractCustomerAddress(array $lines, string $phoneNorm, ?string $name): array
    {
        $thikana = null;
        $upazila = null;
        $district = null;
        $village = null;

        foreach ($lines as $line) {
            $thikana ??= $this->labeledFieldValue($line, ['পুরো ঠিকানা', 'ঠিকানা', 'address', 'thikana']);
            $upazila ??= $this->labeledFieldValue($line, ['উপজেলা', 'upazila', 'থানা']);
            $district ??= $this->labeledFieldValue($line, ['জেলা', 'district', 'zilla', 'zila']);
            $village ??= $this->labeledFieldValue($line, ['গ্রাম', 'village']);
        }

        if ($thikana !== null || $upazila !== null || $district !== null || $village !== null) {
            $parts = array_values(array_unique(array_filter([$thikana, $village, $upazila, $district])));

            return [
                'address' => $parts !== [] ? implode(', ', $parts) : null,
                'city' => $district,
                'area' => $upazila ?: $thikana,
            ];
        }

        $candidates = [];
        foreach ($lines as $line) {
            $linePhone = PhoneNumber::extractFirstBangladeshMobile($line);
            if ($linePhone && PhoneNumber::normalize($linePhone) === $phoneNorm) {
                continue;
            }

            if ($this->looksLikeNoise($line)
                || $this->looksLikeOrderChatter($line)
                || $this->labeledNameValue($line) !== null
                || $this->looksLikeName($line)
                || $this->looksLikeProductLine($line)) {
                continue;
            }

            $cleaned = $this->addressLineWithoutLeadingName($line, $name);
            if ($cleaned === '') {
                continue;
            }

            if ($name !== null && mb_strtolower($cleaned) === mb_strtolower($this->cleanName($name))) {
                continue;
            }

            if ($this->looksLikeAddress($line)
                || $this->looksLikeLocationOnly($line)
                || $this->looksLikeAddress($cleaned)
                || $this->looksLikeLocationOnly($cleaned)) {
                $candidates[] = $cleaned;
            }
        }

        $candidates = array_values(array_unique($candidates));
        $address = $candidates !== [] ? implode(', ', array_slice($candidates, 0, 4)) : null;
        $inferred = $this->inferCityAreaFromParts($this->addressParts($address ?? ''));

        return [
            'address' => $address,
            'city' => $inferred['city'],
            'area' => $inferred['area'],
        ];
    }

    /**
     * @param  list<string>  $labels
     */
    private function labeledFieldValue(string $line, array $labels): ?string
    {
        $quoted = array_map(fn (string $label) => preg_quote($label, '/'), $labels);
        $pattern = '/^(?:'.implode('|', $quoted).')\s*[:：\-]\s*(.+)$/iu';
        if (! preg_match($pattern, trim($line), $matches)) {
            return null;
        }

        $value = $this->cleanAddressFragment((string) $matches[1]);

        return $value !== '' ? $value : null;
    }

    private function labeledLocationValue(string $line): ?string
    {
        return $this->labeledFieldValue($line, [
            'পুরো ঠিকানা', 'ঠিকানা', 'address', 'thikana',
            'উপজেলা', 'upazila', 'থানা',
            'জেলা', 'district', 'zilla', 'zila',
            'গ্রাম', 'village',
        ]);
    }

    private function addressLineWithoutLeadingName(string $line, ?string $name): string
    {
        $parts = preg_split('/\s*,\s*/u', $line) ?: [];
        if (count($parts) < 2) {
            return $this->cleanAddressFragment($line);
        }

        $head = $this->cleanName((string) $parts[0]);
        $extracted = $name !== null ? mb_strtolower($this->cleanName($name)) : null;
        $headMatchesName = $extracted !== null && mb_strtolower($head) === $extracted;
        $words = preg_split('/\s+/u', $head) ?: [];
        $headIsPerson = $this->looksLikeName($head) && (count($words) >= 2 || $headMatchesName);

        if (! $headIsPerson && ! $headMatchesName) {
            return $this->cleanAddressFragment($line);
        }

        array_shift($parts);
        $rest = [];
        foreach ($parts as $part) {
            $cleaned = $this->cleanAddressFragment((string) $part);
            if ($cleaned !== '') {
                $rest[] = $cleaned;
            }
        }

        return $rest !== [] ? implode(', ', $rest) : $this->cleanAddressFragment($line);
    }

    private function cleanAddressFragment(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = preg_replace('/\s*,\s*/u', ', ', $line) ?? $line;
        $line = preg_replace('/,(?:\s*,)+/u', ', ', $line) ?? $line;

        return trim($line, " \t,");
    }

    /**
     * @return list<string>
     */
    private function addressParts(string $address): array
    {
        if ($address === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/u', $address) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part) => $this->cleanAddressFragment($part),
            $parts,
        ), fn (string $part) => $part !== ''));
    }

    /**
     * @param  list<string>  $parts
     * @return array{city:?string, area:?string}
     */
    private function inferCityAreaFromParts(array $parts): array
    {
        if ($parts === []) {
            return ['city' => null, 'area' => null];
        }

        $last = $parts[count($parts) - 1];
        $first = $parts[0];

        if ($this->looksLikeDistrictName($last)) {
            return [
                'city' => $last,
                'area' => count($parts) >= 2 ? $parts[count($parts) - 2] : null,
            ];
        }

        if ($this->looksLikeDistrictName($first)) {
            return [
                'city' => $first,
                'area' => count($parts) >= 2 ? $parts[1] : null,
            ];
        }

        return ['city' => null, 'area' => null];
    }

    private function looksLikeDistrictName(string $line): bool
    {
        $normalized = mb_strtolower($this->cleanAddressFragment($line));

        return in_array($normalized, [
            'dhaka', 'chittagong', 'chattogram', 'khulna', 'rajshahi', 'sylhet',
            'barisal', 'barishal', 'rangpur', 'mymensingh', 'gazipur', 'narayanganj',
            'cumilla', 'comilla', 'jhalokathi', 'jhalakati', 'khagrachari', 'khagrachhari',
            'rangamati', 'bandarban', 'feni', 'noakhali', 'bhola', 'patuakhali',
            'barguna', 'pirojpur', 'rajbari', 'narsingdi', 'kishoreganj', 'brahmanbaria',
            'chandpur', 'lakshmipur', 'shariatpur', 'madaripur', 'gopalganj', 'tangail',
            'jamalpur', 'sherpur', 'netrokona', 'চট্টগ্রাম', 'কুমিল্লা', 'ঢাকা',
        ], true);
    }

    private function labeledNameValue(string $line): ?string
    {
        if (! preg_match('/^(?:আপনার\s+)?(?:নাম|name|nam)\s*[:：\-]\s*(.+)$/iu', trim($line), $matches)) {
            return null;
        }

        $value = $this->cleanName((string) $matches[1]);
        if ($value === '' || ! $this->looksLikeName($value)) {
            return null;
        }

        return $value;
    }

    private function leadingNameFromAddress(string $line): ?string
    {
        if (! str_contains($line, ',')) {
            return null;
        }

        $parts = preg_split('/\s*,\s*/u', $line) ?: [];
        if (count($parts) < 2) {
            return null;
        }

        $head = $this->cleanName((string) $parts[0]);
        if ($head !== '' && $this->looksLikeName($head)) {
            return $head;
        }

        return null;
    }

    private function cleanName(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^[\p{P}\p{Z}]+|[\p{P}\p{Z}]+$/u', '', $line) ?? $line;
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line);
    }

    private function looksLikeName(string $line): bool
    {
        $line = $this->cleanName($line);
        if ($line === '') {
            return false;
        }

        if ($this->looksLikeNoise($line)
            || $this->looksLikeOrderChatter($line)
            || $this->looksLikeAddress($line)
            || $this->looksLikeLocationOnly($line)
            || $this->looksLikeProductLine($line)) {
            return false;
        }

        if (PhoneNumber::extractFirstBangladeshMobile($line)) {
            return false;
        }

        if (preg_match('/\d/u', $line) || str_contains($line, '?') || str_contains($line, '؟')) {
            return false;
        }

        $len = mb_strlen($line);
        if ($len < 2 || $len > 60) {
            return false;
        }

        $words = preg_split('/\s+/u', $line) ?: [];
        if ($words === [] || count($words) > 5) {
            return false;
        }

        foreach ($words as $word) {
            if (! preg_match('/^[\p{L}\p{M}.]+$/u', $word)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeOrderChatter(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));
        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, '?') || str_contains($line, '؟')) {
            return true;
        }

        return (bool) preg_match(
            '/\b(confirm|confrom|order|delivery|parben|rakhen|tarikh|dben|koto|taka|\btk\b|soho|please|plz|lagbe|laglo|dhonnobad|inbox|price)\b|(অর্ডার|কনফার্ম|ডেলিভারি|ধন্যবাদ|পারবেন|রাখেন|তারিখ|দাম)/iu',
            $normalized,
        );
    }

    private function looksLikeLocationOnly(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));
        $normalized = trim((string) preg_replace('/[।.]/u', '', $normalized));

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\b(clinic|building|hospital|college|school|mosque|masjid|thana|sadar|union|ward)\b/iu', $normalized)) {
            return true;
        }

        $barePlaces = [
            'cumilla', 'comilla', 'jhalokathi', 'jhalakati', 'rajapur', 'khagrachari',
            'khagrachhari', 'panchari', 'panchhari', 'chauddagram', 'chaudagram',
            'fatikchhari', 'fatikchari', 'chittagong', 'chattogram', 'rangamati',
            'bandarban', 'feni', 'noakhali', 'bhola', 'patuakhali', 'barguna',
            'pirojpur', 'rajbari', 'narayanganj', 'gazipur', 'narsingdi',
            'kishoreganj', 'brahmanbaria', 'chandpur', 'lakshmipur', 'shariatpur',
            'madaripur', 'gopalganj', 'tangail', 'jamalpur', 'sherpur', 'netrokona',
            'mymensingh', 'tripura', 'munshirhat', 'rajshahi', 'khulna', 'sylhet',
            'barisal', 'barishal', 'rangpur', 'dhaka',
        ];
        if (in_array($normalized, $barePlaces, true)) {
            return true;
        }

        $words = preg_split('/\s+/u', $normalized) ?: [];
        if (count($words) === 1 && preg_match('/(hat|para|pur|ganj|nagar|chara|chhara|chari|bari|bazar|bazaar|gram)$/iu', $normalized)) {
            return true;
        }

        return false;
    }

    private function looksLikeAddress(string $line): bool
    {
        if ($this->looksLikeNoise($line)) {
            return false;
        }

        if (PhoneNumber::extractFirstBangladeshMobile($line) && mb_strlen($line) < 20) {
            return false;
        }

        // House / road / area cues (EN + common BN transliteration / Bangla).
        if (preg_match('/\b(house|road|rd\.?|block|sector|flat|floor|lane|avenue|para|bazar|thana|district|upazila|goli|clinic|building|hospital)\b/iu', $line)) {
            return true;
        }

        if (preg_match('/(বাড়ি|রোড|গলি|থানা|জেলা|উপজেলা|বাজার|মহল্লা)/u', $line)) {
            return true;
        }

        if (preg_match('/\b(dhaka|chittagong|chattogram|khulna|rajshahi|sylhet|barisal|rangpur|mymensingh|gazipur|narayanganj|mirpur|uttara|banani|gulshan|dhanmondi|bashabo|bashaboo|motijheel|farmgate|cumilla|comilla|jhalokathi|jhalakati|khagrachari|khagrachhari)\b/iu', $line)) {
            return true;
        }

        // "Thikana ..." / address labels
        if (preg_match('/^(thikana|address|ঠিকানা)\b/iu', $line)) {
            return true;
        }

        if (substr_count($line, ',') >= 2 && mb_strlen($line) >= 16) {
            return true;
        }

        // Digit-heavy delivery lines (house no + area words already covered; bare long digit lines OK if long enough)
        if (preg_match('/\d/', $line) && mb_strlen($line) >= 12 && ! preg_match('/^(x\s*)?\d+\s*(pcs?|pieces?|টা|টি)?$/iu', $line)) {
            return true;
        }

        // Location guesser can confirm short locality-only lines.
        $guess = $this->locationGuesser->guess($line);

        return $guess !== null;
    }

    private function looksLikeNoise(string $line): bool
    {
        $normalized = mb_strtolower(trim($line));

        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^(hi|hello|hlw|salam|assalamu|ok|okay|acha|accha|jii|ji|haan|han|hmm|hmmm|yes|no|nah|apu|bhai|thanks|thank you|tq|confirm|confrom|done|okay apu|please send this|send this|জি|আচ্ছা)\.?$/iu', $normalized)) {
            return true;
        }

        if (preg_match('/\b(please send|send this|nite chai)\b/iu', $normalized) && mb_strlen($normalized) <= 40) {
            return true;
        }

        // Pure reaction / bargaining chatter without locality cues.
        if (mb_strlen($normalized) <= 40 && preg_match('/\b(valo hobe|ferot|return|advance|এডভান্স|nite chai|parsel|delivery chars?)\b/iu', $normalized)) {
            return ! preg_match('/\b(dhaka|chittagong|chattogram|mirpur|banani|gulshan|thikana|ঠিকানা|house|road)\b/iu', $normalized);
        }

        return false;
    }

    /**
     * Products the customer is closing on — not every photo they asked a price for.
     *
     * @param  Collection<int, ChannelMessage>  $inbound
     * @param  Collection<int, ChannelMessage>  $orderWindow
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function orderedProductLines(Collection $inbound, Collection $orderWindow, ?Carbon $lastPriceAskAt): array
    {
        $windowLines = $this->matchedProductLines($orderWindow, $lastPriceAskAt);
        if ($windowLines !== []) {
            return $windowLines;
        }

        $replyLines = $this->replySelectedProductLines($inbound);
        if ($replyLines !== []) {
            return $replyLines;
        }

        if ($lastPriceAskAt === null) {
            return $this->matchedProductLines($inbound, null);
        }

        return [];
    }

    /**
     * @param  Collection<int, ChannelMessage>  $messages
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function matchedProductLines(Collection $messages, ?Carbon $lastPriceAskAt): array
    {
        $counts = [];
        foreach ($messages as $message) {
            $productId = $this->selectedProductId($message, $lastPriceAskAt);
            if ($productId <= 0) {
                continue;
            }
            $counts[$productId] = ($counts[$productId] ?? 0) + 1;
        }

        return $this->productLinesFromCounts($counts);
    }

    /**
     * @param  Collection<int, ChannelMessage>  $inbound
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function replySelectedProductLines(Collection $inbound): array
    {
        $counts = [];
        foreach ($inbound as $message) {
            $productId = $this->replySelectedProductId($message);
            if ($productId <= 0) {
                continue;
            }
            $counts[$productId] = ($counts[$productId] ?? 0) + 1;
        }

        return $this->productLinesFromCounts($counts);
    }

    /**
     * @param  list<array{message_id?:int,product_id:int,name:string}>  $hits
     * @param  Collection<int, ChannelMessage>  $orderWindow
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function imageProductLinesInWindow(array $hits, Collection $orderWindow, ?Carbon $lastPriceAskAt): array
    {
        $windowIds = $orderWindow->pluck('id')->all();
        $filtered = [];
        foreach ($hits as $hit) {
            $messageId = (int) ($hit['message_id'] ?? 0);
            if ($messageId <= 0 || ! in_array($messageId, $windowIds, true)) {
                continue;
            }
            $message = $orderWindow->firstWhere('id', $messageId);
            if ($message instanceof ChannelMessage && $this->isPriceQueryPhoto($message, $lastPriceAskAt)) {
                continue;
            }
            $filtered[] = $hit;
        }

        return $this->productLinesFromHits($filtered);
    }

    /**
     * @param  Collection<int, ChannelMessage>  $inbound
     */
    private function lastPriceAskAt(Collection $inbound): ?Carbon
    {
        $latest = null;
        foreach ($inbound as $message) {
            if (! $this->matcher->isWhichProductPriceAsk($message)) {
                continue;
            }
            $sentAt = $message->sent_at;
            if (! $sentAt instanceof Carbon) {
                continue;
            }
            if ($latest === null || $sentAt->greaterThan($latest)) {
                $latest = $sentAt;
            }
        }

        return $latest;
    }

    private function selectedProductId(ChannelMessage $message, ?Carbon $lastPriceAskAt): int
    {
        $fromReply = $this->replySelectedProductId($message);
        if ($fromReply > 0) {
            return $fromReply;
        }

        $direct = (int) ($message->matched_product_id ?? 0);
        if ($direct <= 0 || $this->isPriceQueryPhoto($message, $lastPriceAskAt)) {
            return 0;
        }

        return $direct;
    }

    private function replySelectedProductId(ChannelMessage $message): int
    {
        $parent = $message->replyTo;
        if (! $parent || $parent->direction !== ChannelMessage::DIRECTION_OUTBOUND) {
            return 0;
        }

        $productId = (int) ($parent->matched_product_id ?? 0);
        if ($productId > 0) {
            return $productId;
        }

        $payload = is_array($parent->raw_payload) ? $parent->raw_payload : [];

        return (int) ($payload[InboxPriceQueryAutomationService::RAW_PRICED_PRODUCT_ID] ?? 0);
    }

    private function isPriceQueryPhoto(ChannelMessage $message, ?Carbon $lastPriceAskAt): bool
    {
        if ($lastPriceAskAt === null) {
            return false;
        }

        $sentAt = $message->sent_at;
        if (! $sentAt instanceof Carbon) {
            return true;
        }

        return $sentAt->lessThanOrEqualTo($lastPriceAskAt);
    }

    /**
     * @param  list<array{product_id:int,name:string,match_percent?:float,newer_weight?:float}>  $hits
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function productLinesFromHits(array $hits): array
    {
        $counts = [];
        $names = [];
        foreach ($hits as $hit) {
            $productId = (int) ($hit['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $counts[$productId] = ($counts[$productId] ?? 0) + 1;
            $names[$productId] = (string) ($hit['name'] ?? '');
        }

        return $this->productLinesFromCounts($counts, $names);
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $names
     * @return list<array{product_id:int, product_name:string, quantity:int}>
     */
    private function productLinesFromCounts(array $counts, array $names = []): array
    {
        if ($counts === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', array_keys($counts))
            ->get(['id', 'name'])
            ->keyBy('id');

        $lines = [];
        foreach ($counts as $productId => $quantity) {
            $product = $products->get($productId);
            $name = $product ? (string) $product->name : trim((string) ($names[$productId] ?? ''));
            if (! $product && $name === '') {
                continue;
            }

            $lines[] = [
                'product_id' => (int) $productId,
                'product_name' => $name !== '' ? $name : 'Product',
                'quantity' => max(1, (int) $quantity),
            ];
        }

        return $lines;
    }

    /**
     * @return array{product_id:?int, product_name:?string, quantity:int, weak_points: list<string>}
     */
    private function matchProductFromText(string $rawText): array
    {
        $weak = [];
        $quantity = 1;

        foreach (preg_split('/\R+/u', $rawText) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || PhoneNumber::extractFirstBangladeshMobile($line) || $this->looksLikeNoise($line)) {
                continue;
            }

            if ($this->looksLikeAddress($line) && ! $this->looksLikeProductLine($line)) {
                continue;
            }

            $candidate = preg_replace('/\s*(x\s*)?\d+\s*(pcs?|pieces?|টা|টি)?$/iu', '', $line) ?? $line;
            $candidate = trim($candidate);
            if ($candidate === '' || mb_strlen($candidate) < 3) {
                continue;
            }

            $exactId = $this->matchProductByName($candidate, fuzzy: false);
            if ($exactId) {
                if (preg_match('/(?:x\s*)?(\d+)\s*(?:pcs?|pieces?|টা|টি)?$/iu', $line, $qtyMatch)) {
                    $quantity = max(1, (int) $qtyMatch[1]);
                }

                return [
                    'product_id' => $exactId,
                    'product_name' => $candidate,
                    'quantity' => $quantity,
                    'weak_points' => [],
                ];
            }

            // Fuzzy LIKE only for reasonably specific labels.
            if (mb_strlen($candidate) >= 5) {
                $fuzzyId = $this->matchProductByName($candidate, fuzzy: true);
                if ($fuzzyId) {
                    $weak[] = self::WEAK_PRODUCT_NAME_FUZZY;
                    if (preg_match('/(?:x\s*)?(\d+)\s*(?:pcs?|pieces?|টা|টি)?$/iu', $line, $qtyMatch)) {
                        $quantity = max(1, (int) $qtyMatch[1]);
                    }

                    return [
                        'product_id' => $fuzzyId,
                        'product_name' => $candidate,
                        'quantity' => $quantity,
                        'weak_points' => $weak,
                    ];
                }
            }
        }

        return [
            'product_id' => null,
            'product_name' => null,
            'quantity' => 1,
            'weak_points' => [],
        ];
    }

    private function looksLikeProductLine(string $line): bool
    {
        return (bool) preg_match('/\b(kurti|sharee|saree|orna|set|dress|top|pant|hijab|abaya|jewellery|jewelry|necklace|earring|sku)\b/iu', $line);
    }

    /**
     * Local image matching only — never builds Gemini inline_data parts.
     * Persists each inbound photo once; reuses media_path / media_dhash / media_dct_hash.
     *
     * @param  Collection<int, ChannelMessage>  $messages
     * @return array{
     *     product_id:?int,
     *     product_name:?string,
     *     products: list<array{product_id:int,product_name:string,quantity:int}>,
     *     auto_hits: list<array{message_id:int,product_id:int,name:string,match_percent:float,dhash:?string,newer_weight:float}>,
     *     candidates: list<array{product_id:int,name:string,match_percent:float}>,
     *     weak_points: list<string>
     * }
     */
    private function matchProductFromImages(Collection $messages): array
    {
        $weak = [];
        $candidates = [];

        $imageMessages = $messages->filter(
            fn (ChannelMessage $message) => $message->hasMedia() && $message->isImageAttachment()
        )->values();

        if ($imageMessages->isEmpty()) {
            $weak[] = self::WEAK_IMAGE_NONE;

            return [
                'product_id' => null,
                'product_name' => null,
                'products' => [],
                'auto_hits' => [],
                'candidates' => [],
                'weak_points' => $weak,
            ];
        }

        if (! ProductImage::query()->whereNotNull('match_signature')->exists()) {
            $weak[] = self::WEAK_CATALOG_UNHASHED;
        }

        /** @var list<array{message_id:int,product_id:int,name:string,match_percent:float,dhash:?string,newer_weight:float}> $autoHits */
        $autoHits = [];
        $downloadFailures = 0;
        $hadBytes = false;
        $total = $imageMessages->count();

        foreach ($imageMessages as $index => $message) {
            try {
                $cached = $this->inboundMedia->ensureCached($message);
            } catch (Throwable $e) {
                Log::warning('Channel draft inbound media cache failed.', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
                $downloadFailures++;

                continue;
            }

            if ($cached === null) {
                $downloadFailures++;

                continue;
            }

            $hadBytes = true;

            try {
                $resolved = $this->imageMatches->resolve($message);
                $rows = $this->imageMatches->candidateRows($resolved, ProductImageHashService::TOP_MATCHES, includeAttached: true);
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => (float) $row['match_percent'] >= ProductImageMatcher::suggestScore() * 100,
                ));

                foreach ($rows as $row) {
                    $candidates[] = [
                        'product_id' => (int) $row['product_id'],
                        'name' => (string) $row['name'],
                        'match_percent' => (float) $row['match_percent'],
                    ];
                }

                $attached = $this->imageMatches->bestAutoMatch($resolved);
                if ($attached !== null) {
                    // Newer photos weigh more (last image in the window ≈ "this one").
                    $newerWeight = 1.0 + (($index + 1) / max(1, $total));
                    $autoHits[] = [
                        'message_id' => (int) $message->id,
                        'product_id' => (int) $attached['product_id'],
                        'name' => (string) $attached['name'],
                        'match_percent' => (float) $attached['match_percent'],
                        'dhash' => $cached['dhash'] ?? null,
                        'newer_weight' => $newerWeight,
                    ];
                    $candidates[] = [
                        'product_id' => (int) $attached['product_id'],
                        'name' => (string) $attached['name'],
                        'match_percent' => (float) $attached['match_percent'],
                    ];
                } elseif ($rows !== []) {
                    $weak[] = self::WEAK_IMAGE_BELOW_AUTO;
                } else {
                    $weak[] = self::WEAK_IMAGE_NO_AUTO_MATCH;
                }
            } catch (Throwable $e) {
                Log::warning('Channel draft image hash failed.', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($downloadFailures > 0 && ! $hadBytes) {
            $weak[] = self::WEAK_IMAGE_DOWNLOAD_FAILED;
        }

        // Deduplicate candidates by product_id keeping highest percent.
        $byProduct = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['product_id'];
            if (! isset($byProduct[$id]) || $candidate['match_percent'] > $byProduct[$id]['match_percent']) {
                $byProduct[$id] = $candidate;
            }
        }
        $candidates = array_values($byProduct);
        usort($candidates, fn (array $a, array $b) => $b['match_percent'] <=> $a['match_percent']);

        $winner = $this->voteAutoProduct($autoHits);
        $products = $this->productLinesFromHits($autoHits);

        return [
            'product_id' => $products[0]['product_id'] ?? ($winner ? (int) $winner['product_id'] : null),
            'product_name' => $products[0]['product_name'] ?? ($winner ? (string) $winner['name'] : null),
            'products' => $products,
            'auto_hits' => $autoHits,
            'candidates' => array_slice($candidates, 0, 5),
            'weak_points' => array_values(array_unique($weak)),
        ];
    }

    /**
     * Cluster near-duplicate incoming hashes and vote for the winning product.
     * Newer photos in the window get a higher weight.
     *
     * @param  list<array{message_id:int,product_id:int,name:string,match_percent:float,dhash:?string,newer_weight:float}>  $autoHits
     * @return array{product_id:int,name:string}|null
     */
    private function voteAutoProduct(array $autoHits): ?array
    {
        if ($autoHits === []) {
            return null;
        }

        /** @var array<int, array{product_id:int,name:string,score:float,best_percent:float}> $scores */
        $scores = [];

        foreach ($autoHits as $hit) {
            $id = (int) $hit['product_id'];
            $weight = (float) $hit['newer_weight'] * (1.0 + ((float) $hit['match_percent'] / 100));
            if (! isset($scores[$id])) {
                $scores[$id] = [
                    'product_id' => $id,
                    'name' => (string) $hit['name'],
                    'score' => 0.0,
                    'best_percent' => 0.0,
                ];
            }
            $scores[$id]['score'] += $weight;
            $scores[$id]['best_percent'] = max($scores[$id]['best_percent'], (float) $hit['match_percent']);
            $scores[$id]['name'] = (string) $hit['name'];
        }

        usort($scores, function (array $a, array $b): int {
            $byScore = $b['score'] <=> $a['score'];
            if ($byScore !== 0) {
                return $byScore;
            }

            return $b['best_percent'] <=> $a['best_percent'];
        });

        $top = $scores[0] ?? null;

        return $top ? ['product_id' => $top['product_id'], 'name' => $top['name']] : null;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function needsGapFill(array $current): bool
    {
        return ! filled($current['name'] ?? null)
            || ! filled($current['address'] ?? null)
            || (empty($current['product_id']) && empty($current['product_name']) && empty($current['products']));
    }

    /**
     * Text-only Gemini gap fill. Image bytes must never be included (locked).
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function parseGapsWithGemini(string $rawText, array $current): array
    {
        // Product ID comes from local hash matching — do not dump the catalog for vision.
        $productHint = '';
        if (! empty($current['product_id'])) {
            $productHint = 'Product already matched locally as #'.(int) $current['product_id']
                .' ('.(string) ($current['product_name'] ?? '').'). Do not change product_id unless clearly wrong.';
        }

        $system = <<<'PROMPT'
You extract Bangladesh e-commerce order details from recent Messenger customer TEXT messages only.
Return ONLY JSON with keys: name, phone, address, city, area, product_id, product_name, quantity
Rules:
- Only fill fields you are confident about; use null otherwise.
- Do not invent phone numbers.
- address must be a short delivery address only — never paste the whole chat or chitchat.
- product_id / product_name: leave null unless the TEXT clearly names a product; do not invent ids.
- quantity defaults to 1.
- You are NOT given product photos — ignore any request to identify jewelry from images.
PROMPT;

        $known = json_encode([
            'name' => $current['name'] ?? null,
            'phone' => $current['phone'] ?? null,
            'address' => $current['address'] ?? null,
            'product_id' => $current['product_id'] ?? null,
            'product_name' => $current['product_name'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $userText = "Already extracted locally (do not contradict phone):\n{$known}\n"
            .($productHint !== '' ? $productHint."\n" : '')
            ."\nCustomer messages:\n".($rawText !== '' ? $rawText : '(no text)');

        $parts = [['text' => $userText]];
        $data = $this->gemini->generateJsonFromParts($system, $parts);

        $address = $this->nullableString($data['address'] ?? null);
        if ($address && ($this->looksLikeNoise($address) || substr_count($address, "\n") > 2 || mb_strlen($address) > 255)) {
            $address = null;
        }

        return [
            'name' => $this->nullableString($data['name'] ?? null),
            'address' => $address,
            'city' => $this->nullableString($data['city'] ?? null),
            'area' => $this->nullableString($data['area'] ?? null),
            'product_id' => isset($data['product_id']) && is_numeric($data['product_id']) ? (int) $data['product_id'] : null,
            'product_name' => $this->nullableString($data['product_name'] ?? null),
            'quantity' => isset($data['quantity']) && is_numeric($data['quantity']) ? max(1, (int) $data['quantity']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $gap
     * @param  list<string>  $weakPoints
     * @return array<string, mixed>
     */
    private function mergeGapsOnly(array $base, array $gap, array $weakPoints = []): array
    {
        foreach (['name', 'address', 'city', 'area', 'product_id', 'product_name', 'quantity'] as $key) {
            $gapValue = $gap[$key] ?? null;
            if (! filled($gapValue)) {
                continue;
            }

            $current = $base[$key] ?? null;

            if (! filled($current)) {
                $base[$key] = $gapValue;
            }
        }

        return $base;
    }

    private function matchProductByName(string $name, bool $fuzzy = true): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $exact = Product::query()
            ->where('is_published', true)
            ->where(function ($q) use ($name) {
                $q->where('name', $name)->orWhere('sku', $name);
            })
            ->value('id');

        if ($exact) {
            return (int) $exact;
        }

        if (! $fuzzy) {
            return null;
        }

        $like = Product::query()
            ->where('is_published', true)
            ->where('name', 'like', '%'.$name.'%')
            ->orderByRaw('LENGTH(name) asc')
            ->orderBy('name')
            ->value('id');

        return $like ? (int) $like : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function missingFields(array $result): array
    {
        $missing = [];
        foreach (['name', 'phone', 'address'] as $field) {
            if (! filled($result[$field] ?? null)) {
                $missing[] = $field;
            }
        }
        if (empty($result['product_id']) && empty($result['product_name']) && empty($result['products'])) {
            $missing[] = 'product';
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $weakPoints
     */
    private function confidence(array $result, array $weakPoints): float
    {
        $score = 0.0;

        if (filled($result['phone'] ?? null)) {
            $score += 0.30;
        }

        if (filled($result['name'] ?? null)) {
            $score += in_array(self::WEAK_NAME_HEURISTIC, $weakPoints, true) ? 0.10 : 0.20;
        }

        if (filled($result['address'] ?? null)) {
            $score += in_array(self::WEAK_ADDRESS_UNRESOLVED, $weakPoints, true) ? 0.20 : 0.25;
        }

        if (! empty($result['product_id']) || ! empty($result['products'])) {
            $score += in_array(self::WEAK_PRODUCT_NAME_FUZZY, $weakPoints, true) ? 0.15 : 0.25;
        } elseif (filled($result['product_name'] ?? null)) {
            $score += 0.08;
        }

        return round(min(1.0, $score), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $rawText): array
    {
        return [
            'name' => null,
            'phone' => null,
            'address' => null,
            'city' => null,
            'area' => null,
            'cityId' => null,
            'areaId' => null,
            'product_id' => null,
            'product_name' => null,
            'products' => [],
            'quantity' => null,
            'missing' => [],
            'weak_points' => [],
            'source' => 'none',
            'confidence' => 0.0,
            'raw_text' => $rawText,
            'image_matches' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
