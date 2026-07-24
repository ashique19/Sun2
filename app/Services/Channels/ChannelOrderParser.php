<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\OrderPasteParser;
use App\Services\Storefront\AddressLocationGuesser;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelOrderParser
{
    public function __construct(
        private GeminiClient $gemini,
        private OrderPasteParser $pasteParser,
        private AddressLocationGuesser $locationGuesser,
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
     *     source: string,
     *     confidence: float,
     *     raw_text: string
     * }
     */
    public function parseConversation(ChannelConversation $conversation): array
    {
        $conversation->loadMissing(['messages']);

        $inbound = $conversation->messages
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->values();

        $textChunks = [];
        $imageParts = [];

        foreach ($inbound as $message) {
            $body = trim((string) ($message->body ?? ''));
            if ($body !== '') {
                $textChunks[] = $body;
            }

            if (filled($message->media_url)) {
                $inline = $this->downloadImagePart((string) $message->media_url, $message->media_mime);
                if ($inline) {
                    $imageParts[] = $inline;
                }
            }
        }

        $rawText = trim(implode("\n", $textChunks));
        $result = $this->emptyResult($rawText);

        if ($rawText === '' && $imageParts === []) {
            $result['missing'] = ['name', 'phone', 'address', 'product'];

            return $result;
        }

        if ($this->gemini->isConfigured()) {
            try {
                $parsed = $this->parseWithGemini($rawText, $imageParts);
                $result = $this->merge($result, $parsed);
                $result['source'] = 'gemini';
            } catch (Throwable $e) {
                Log::warning('Channel order Gemini parse failed; falling back.', [
                    'conversation_id' => $conversation->id,
                    'message' => $e->getMessage(),
                ]);

                if ($rawText !== '') {
                    $paste = $this->pasteParser->parse($rawText);
                    $result = $this->merge($result, [
                        'name' => $paste['name'],
                        'phone' => $paste['phone'],
                        'address' => $paste['address'],
                        'city' => $paste['city'],
                        'area' => $paste['area'],
                        'cityId' => $paste['cityId'],
                        'areaId' => $paste['areaId'],
                    ]);
                    $result['source'] = 'heuristic';
                }
            }
        } elseif ($rawText !== '') {
            $paste = $this->pasteParser->parse($rawText);
            $result = $this->merge($result, [
                'name' => $paste['name'],
                'phone' => $paste['phone'],
                'address' => $paste['address'],
                'city' => $paste['city'],
                'area' => $paste['area'],
                'cityId' => $paste['cityId'],
                'areaId' => $paste['areaId'],
            ]);
            $result['source'] = 'heuristic';
        }

        if (! $result['phone']) {
            $result['phone'] = PhoneNumber::extractFirstBangladeshMobile($rawText);
        } elseif (PhoneNumber::isValidBangladeshMobile($result['phone'])) {
            $result['phone'] = PhoneNumber::display($result['phone']);
        }

        if ((! $result['cityId'] || ! $result['areaId']) && filled($result['address'])) {
            $guess = $this->locationGuesser->guess($result['address']);
            if ($guess) {
                $result['cityId'] = $result['cityId'] ?: $guess['city_id'];
                $result['areaId'] = $result['areaId'] ?: $guess['area_id'];
                if (! $result['city'] || ! $result['area']) {
                    [$areaLabel, $cityLabel] = array_pad(explode(',', $guess['label'], 2), 2, null);
                    $result['area'] = $result['area'] ?: trim((string) $areaLabel);
                    $result['city'] = $result['city'] ?: trim((string) $cityLabel);
                }
            }
        }

        if (! $result['product_id'] && filled($result['product_name'])) {
            $result['product_id'] = $this->matchProductByName((string) $result['product_name']);
        }

        if (! $result['product_id'] && $rawText !== '') {
            foreach (preg_split('/\R+/u', $rawText) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || PhoneNumber::extractFirstBangladeshMobile($line)) {
                    continue;
                }

                // Strip trailing qty hints like "2pcs" / "x2"
                $candidate = preg_replace('/\s*(x\s*)?\d+\s*(pcs?|pieces?|টা|টি)?$/iu', '', $line) ?? $line;
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }

                $matchedId = $this->matchProductByName($candidate);
                if ($matchedId) {
                    $result['product_id'] = $matchedId;
                    $result['product_name'] = $candidate;
                    if (preg_match('/(?:x\s*)?(\d+)\s*(?:pcs?|pieces?|টা|টি)?$/iu', $line, $qtyMatch)) {
                        $result['quantity'] = max(1, (int) $qtyMatch[1]);
                    }
                    break;
                }
            }
        }

        $result['quantity'] = max(1, (int) ($result['quantity'] ?? 1));
        $result['missing'] = $this->missingFields($result);
        $result['confidence'] = $this->confidence($result);
        $result['raw_text'] = $rawText;

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $imageParts
     * @return array<string, mixed>
     */
    private function parseWithGemini(string $rawText, array $imageParts): array
    {
        $catalog = Product::query()
            ->where('is_published', true)
            ->orderBy('name')
            ->limit(80)
            ->get(['id', 'name', 'sku'])
            ->map(fn (Product $p) => '#'.$p->id.' '.$p->name.($p->sku ? ' ('.$p->sku.')' : ''))
            ->implode("\n");

        $system = <<<'PROMPT'
You extract Bangladesh e-commerce order details from Messenger/WhatsApp customer messages (Bangla or English) and optional product photos.
Return ONLY JSON with keys:
name, phone, address, city, area, product_id, product_name, quantity
Rules:
- phone must be Bangladesh mobile 01XXXXXXXXX when possible (convert Bangla digits, ignore spaces).
- Prefer English city/area names when both Bangla and English appear.
- address is the delivery address without name/phone.
- product_id must be an integer id from the catalog when confident, else null.
- product_name is the best product label from text or photo.
- quantity defaults to 1.
- Use null for unknown fields. Do not invent phone numbers.
PROMPT;

        $userText = "Catalog:\n".($catalog !== '' ? $catalog : '(empty)')."\n\nCustomer messages:\n".($rawText !== '' ? $rawText : '(image only)');

        $parts = [['text' => $userText], ...$imageParts];
        $data = $this->gemini->generateJsonFromParts($system, $parts);

        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        if ($phone === '') {
            $phone = null;
        }

        return [
            'name' => $this->nullableString($data['name'] ?? null),
            'phone' => $phone ?? PhoneNumber::extractFirstBangladeshMobile($rawText),
            'address' => $this->nullableString($data['address'] ?? null),
            'city' => $this->nullableString($data['city'] ?? null),
            'area' => $this->nullableString($data['area'] ?? null),
            'product_id' => isset($data['product_id']) && is_numeric($data['product_id']) ? (int) $data['product_id'] : null,
            'product_name' => $this->nullableString($data['product_name'] ?? null),
            'quantity' => isset($data['quantity']) && is_numeric($data['quantity']) ? max(1, (int) $data['quantity']) : 1,
        ];
    }

    /**
     * @return array{inline_data: array{mime_type: string, data: string}}|null
     */
    private function downloadImagePart(string $url, ?string $mime): ?array
    {
        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return null;
            }

            $resolvedMime = $mime
                ?: $response->header('Content-Type')
                ?: 'image/jpeg';
            $resolvedMime = explode(';', $resolvedMime)[0];

            return [
                'inline_data' => [
                    'mime_type' => $resolvedMime,
                    'data' => base64_encode($bytes),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to download channel media for Gemini.', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function matchProductByName(string $name): ?int
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

        $like = Product::query()
            ->where('is_published', true)
            ->where('name', 'like', '%'.$name.'%')
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
     */
    private function confidence(array $result): float
    {
        $score = 0.0;
        foreach (['name', 'phone', 'address'] as $field) {
            if (filled($result[$field] ?? null)) {
                $score += 0.25;
            }
        }
        if (! empty($result['product_id'])) {
            $score += 0.25;
        } elseif (filled($result['product_name'] ?? null)) {
            $score += 0.1;
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
            'quantity' => 1,
            'missing' => [],
            'source' => 'none',
            'confidence' => 0.0,
            'raw_text' => $rawText,
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
