<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageEmbeddingService;
use App\Services\Admin\ProductImageHashService;
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
        private ProductImageHashService $imageHasher,
        private ChannelInboundMediaService $inboundMedia,
        private ProductImageMatchMemoryService $matchMemory,
        private ProductImageEmbeddingService $embeddings,
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
        $conversation->loadMissing(['messages']);

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

        $nameMatch = $this->matchProductFromText($rawText);
        if ($nameMatch['product_id']) {
            $result['product_id'] = $nameMatch['product_id'];
            $result['product_name'] = $nameMatch['product_name'];
            $result['quantity'] = $nameMatch['quantity'];
            $weak = array_merge($weak, $nameMatch['weak_points']);
        }

        $imageMatch = $this->matchProductFromImages($orderWindow);
        $result['image_matches'] = $imageMatch['candidates'];
        $weak = array_merge($weak, $imageMatch['weak_points']);

        if (! $result['product_id'] && $imageMatch['product_id']) {
            $result['product_id'] = $imageMatch['product_id'];
            $result['product_name'] = $imageMatch['product_name'];
            $result['source'] = 'local+image';
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

        // Include one message of context before the phone when present.
        $start = max(0, $phoneIndex - 1);

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

        $name = null;
        $addressCandidates = [];
        $discardedNoise = 0;
        $phoneNorm = PhoneNumber::normalize($phone);

        foreach ($lines as $index => $line) {
            $linePhone = PhoneNumber::extractFirstBangladeshMobile($line);
            if ($linePhone && PhoneNumber::normalize($linePhone) === $phoneNorm) {
                // Name often sits on the line immediately before the phone.
                if ($name === null && $index > 0) {
                    $prev = $lines[$index - 1];
                    if ($this->looksLikeName($prev)) {
                        $name = $prev;
                        $weak[] = self::WEAK_NAME_HEURISTIC;
                    }
                }

                continue;
            }

            if ($this->looksLikeNoise($line)) {
                $discardedNoise++;

                continue;
            }

            if ($name === null && $this->looksLikeName($line)) {
                $name = $line;
                $weak[] = self::WEAK_NAME_HEURISTIC;

                continue;
            }

            if ($this->looksLikeAddress($line)) {
                $addressCandidates[] = $line;
            } else {
                $discardedNoise++;
            }
        }

        if ($discardedNoise >= 3) {
            $weak[] = self::WEAK_CHATTY_TRANSCRIPT;
        }

        $address = null;
        if ($addressCandidates !== []) {
            // Keep at most a few address-like lines — never the whole chat.
            $address = implode(', ', array_slice($addressCandidates, 0, 3));
            if (mb_strlen($address) > 255) {
                $address = mb_substr($address, 0, 255);
            }
        }

        return [
            'fields' => [
                'name' => $name,
                'address' => $address,
            ],
            'weak_points' => $weak,
        ];
    }

    private function looksLikeName(string $line): bool
    {
        if ($this->looksLikeNoise($line) || $this->looksLikeAddress($line)) {
            return false;
        }

        if (PhoneNumber::extractFirstBangladeshMobile($line)) {
            return false;
        }

        if (preg_match('/\d{4,}/', $line)) {
            return false;
        }

        $len = mb_strlen($line);

        return $len >= 2 && $len <= 60;
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
        if (preg_match('/\b(house|road|rd\.?|block|sector|flat|floor|lane|avenue|para|bazar|thana|district|upazila|goli|রoad)\b/iu', $line)) {
            return true;
        }

        if (preg_match('/(বাড়ি|রোড|রোড|গলি|থানা|জেলা|উপজেলা|বাজার|মহল্লা)/u', $line)) {
            return true;
        }

        if (preg_match('/\b(dhaka|chittagong|chattogram|khulna|rajshahi|sylhet|barisal|rangpur|mymensingh|gazipur|narayanganj|mirpur|uttara|banani|gulshan|dhanmondi|bashabo|bashaboo|motijheel|farmgate)\b/iu', $line)) {
            return true;
        }

        // "Thikana ..." / address labels
        if (preg_match('/^(thikana|address|ঠিকানা)\b/iu', $line)) {
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

        if (preg_match('/^(hi|hello|hlw|salam|assalamu|ok|okay|acha|accha|jii|ji|haan|han|hmm|hmmm|yes|no|nah|apu|bhai|thanks|thank you|tq|confirm|confrom|done|okay apu|please send this|send this)\.?$/iu', $normalized)) {
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
     *     candidates: list<array{product_id:int,name:string,match_percent:float}>,
     *     weak_points: list<string>
     * }
     */
    private function matchProductFromImages(Collection $messages): array
    {
        $weak = [];
        $candidates = [];
        $autoPercent = (float) config(
            'channels.ai_draft.image_match_auto_percent',
            ProductImageHashService::AUTO_MATCH_PERCENT,
        );
        $minPercent = (float) config(
            'channels.ai_draft.image_match_min_percent',
            ProductImageHashService::MIN_MATCH_PERCENT,
        );

        $imageMessages = $messages->filter(
            fn (ChannelMessage $message) => $message->hasMedia() && $message->isImageAttachment()
        )->values();

        if ($imageMessages->isEmpty()) {
            $weak[] = self::WEAK_IMAGE_NONE;

            return [
                'product_id' => null,
                'product_name' => null,
                'candidates' => [],
                'weak_points' => $weak,
            ];
        }

        $hashedCount = ProductImage::query()
            ->where(function ($query): void {
                $query->whereNotNull('perceptual_hash')
                    ->orWhereNotNull('perceptual_hashes')
                    ->orWhereNotNull('dct_hash');
            })
            ->count();
        if ($hashedCount === 0) {
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

            if ($hashedCount === 0) {
                continue;
            }

            try {
                $memory = $this->matchMemory->lookup(
                    $cached['dhash'] ?? null,
                    $cached['dct_hash'] ?? null,
                );

                if ($memory !== null) {
                    $product = Product::query()
                        ->whereKey($memory['product_id'])
                        ->where('is_published', true)
                        ->first();

                    if ($product !== null) {
                        $newerWeight = 1.0 + (($index + 1) / max(1, $total));
                        $autoHits[] = [
                            'message_id' => (int) $message->id,
                            'product_id' => (int) $product->id,
                            'name' => (string) $product->name,
                            'match_percent' => 100.0,
                            'dhash' => $cached['dhash'] ?? null,
                            'newer_weight' => $newerWeight,
                        ];
                        $candidates[] = [
                            'product_id' => (int) $product->id,
                            'name' => (string) $product->name,
                            'match_percent' => 100.0,
                        ];

                        continue;
                    }
                }

                // Prefer crop-aware binary match; falls back to cached full-frame / DCT hashes.
                $matches = $this->imageHasher->findTopMatchesFromBinary(
                    $cached['bytes'],
                    ProductImageHashService::TOP_MATCHES,
                    $minPercent,
                );
                $matches = $this->filterPublishedMatches($matches);

                if ($matches === [] && filled($cached['dhash'] ?? null)) {
                    $matches = $this->filterPublishedMatches(
                        $this->imageHasher->findTopMatches((string) $cached['dhash'], ProductImageHashService::TOP_MATCHES, $minPercent)
                    );
                }
                if ($matches === [] && filled($cached['dct_hash'] ?? null)) {
                    $matches = $this->filterPublishedMatches(
                        $this->imageHasher->findTopMatches((string) $cached['dct_hash'], ProductImageHashService::TOP_MATCHES, $minPercent)
                    );
                }

                if ($matches === []) {
                    $embeddingMatch = $this->embeddings->findBestAutoMatchFromBinary($cached['bytes']);
                    if ($embeddingMatch !== null) {
                        $matches = [$embeddingMatch];
                    }
                }

                foreach ($matches as $match) {
                    $candidates[] = [
                        'product_id' => (int) $match['product_id'],
                        'name' => (string) $match['name'],
                        'match_percent' => (float) $match['match_percent'],
                    ];
                }

                $top = $matches[0] ?? null;
                if ($top && (float) $top['match_percent'] >= $autoPercent) {
                    // Newer photos weigh more (last image in the window ≈ "this one").
                    $newerWeight = 1.0 + (($index + 1) / max(1, $total));
                    $autoHits[] = [
                        'message_id' => (int) $message->id,
                        'product_id' => (int) $top['product_id'],
                        'name' => (string) $top['name'],
                        'match_percent' => (float) $top['match_percent'],
                        'dhash' => $cached['dhash'] ?? null,
                        'newer_weight' => $newerWeight,
                    ];
                } elseif ($top) {
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

        return [
            'product_id' => $winner ? (int) $winner['product_id'] : null,
            'product_name' => $winner ? (string) $winner['name'] : null,
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
     * @param  list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>  $matches
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    private function filterPublishedMatches(array $matches): array
    {
        if ($matches === []) {
            return [];
        }

        $ids = array_map(fn (array $m) => (int) $m['product_id'], $matches);
        $published = Product::query()
            ->whereIn('id', $ids)
            ->where('is_published', true)
            ->pluck('id')
            ->all();
        $publishedSet = array_flip($published);

        return array_values(array_filter(
            $matches,
            fn (array $m) => isset($publishedSet[(int) $m['product_id']]),
        ));
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function needsGapFill(array $current): bool
    {
        return ! filled($current['name'] ?? null)
            || ! filled($current['address'] ?? null)
            || (empty($current['product_id']) && empty($current['product_name']));
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
            $canOverwriteHeuristicName = $key === 'name'
                && in_array(self::WEAK_NAME_HEURISTIC, $weakPoints, true);

            if (! filled($current) || $canOverwriteHeuristicName) {
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
        if (empty($result['product_id']) && empty($result['product_name'])) {
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

        if (! empty($result['product_id'])) {
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
