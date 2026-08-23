<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\ProductImageHashService;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelMessageImageMatchService
{
    public function __construct(
        private ProductImageHashService $hasher,
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * Best published catalog match at or above the auto threshold (90%).
     * Tries full-frame first, then screenshot photo-panel / chrome-trim, then center crops.
     *
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    public function bestAutoMatch(ChannelMessage $message): ?array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return null;
        }

        $downloaded = $this->downloadInboundImageBytes($message);
        if ($downloaded === null) {
            return null;
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($downloaded['bytes']) < $minBytes) {
            return null;
        }

        try {
            $top = $this->hasher->findBestAutoMatchFromBinary($downloaded['bytes']);
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image hash failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($top === null || (float) $top['match_percent'] < ProductImageHashService::AUTO_MATCH_PERCENT) {
            return null;
        }

        $productId = (int) $top['product_id'];
        $published = Product::query()
            ->whereKey($productId)
            ->where('is_published', true)
            ->exists();

        if (! $published) {
            return null;
        }

        return [
            'product_id' => $productId,
            'name' => (string) $top['name'],
            'match_percent' => (float) $top['match_percent'],
            'strategy' => (string) ($top['strategy'] ?? 'query_full_vs_catalog_full'),
        ];
    }

    /**
     * Manual staff search fallback for screenshot-heavy inbound photos.
     *
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    public function screenshotFallbackMatches(ChannelMessage $message, int $limit = ProductImageHashService::TOP_MATCHES): array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return [];
        }

        $downloaded = $this->downloadInboundImageBytes($message);
        if ($downloaded === null) {
            return [];
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($downloaded['bytes']) < $minBytes) {
            return [];
        }

        try {
            return $this->hasher->findTopMatchesFromBinary(
                $downloaded['bytes'],
                $limit,
                ProductImageHashService::MIN_MATCH_PERCENT,
            );
        } catch (Throwable $e) {
            Log::debug('Inbox screenshot fallback image match failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function downloadInboundImageBytes(ChannelMessage $message): ?array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return null;
        }

        $url = trim((string) ($message->media_url ?? ''));
        if ($url === '') {
            return null;
        }

        return $this->downloadMediaBytes($url, $message->media_mime);
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    private function downloadMediaBytes(string $url, ?string $mime): ?array
    {
        try {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $relative = ltrim(str_replace('\\', '/', $url), '/');
                if (str_contains($relative, '..')) {
                    return null;
                }

                $absolute = public_path($relative);
                if (! is_file($absolute)) {
                    return null;
                }

                $bytes = file_get_contents($absolute);
                if ($bytes === false || $bytes === '') {
                    return null;
                }

                return [
                    'bytes' => $bytes,
                    'mime' => $mime ?: (mime_content_type($absolute) ?: 'image/jpeg'),
                ];
            }

            $token = $this->tokens->token();
            $response = null;

            if ($token !== '' && $this->mediaUrlNeedsToken($url)) {
                $response = Http::timeout(20)
                    ->withOptions(['allow_redirects' => true])
                    ->withToken($token)
                    ->get($url);

                if (! $response->successful()) {
                    $response = Http::timeout(20)
                        ->withOptions(['allow_redirects' => true])
                        ->get($this->withAccessTokenQuery($url, $token));
                }
            } else {
                $response = Http::timeout(20)
                    ->withOptions(['allow_redirects' => true])
                    ->get($url);
            }

            if ($response === null || ! $response->successful()) {
                return null;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return null;
            }

            $contentType = (string) ($response->header('Content-Type') ?: $mime ?: 'image/jpeg');
            $contentType = explode(';', $contentType)[0];

            return [
                'bytes' => $bytes,
                'mime' => $contentType !== '' ? $contentType : 'image/jpeg',
            ];
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image download failed.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function mediaUrlNeedsToken(string $url): bool
    {
        return str_contains($url, 'fbcdn')
            || str_contains($url, 'facebook.com')
            || str_contains($url, 'fbsbx.com')
            || str_contains($url, 'lookaside');
    }

    private function withAccessTokenQuery(string $url, string $token): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        if (isset($query['access_token']) && $query['access_token'] !== '') {
            return $url;
        }

        $query['access_token'] = $token;

        $rebuilt = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .'?'.http_build_query($query);

        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
