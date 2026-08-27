<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Services\Admin\ProductImageHashService;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Download, persist, and hash inbound channel images once.
 * Subsequent draft/inbox matching reads local bytes + cached hashes — never re-sends pixels to Gemini.
 */
class ChannelInboundMediaService
{
    public function __construct(
        private ProductImageHashService $hasher,
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * Ensure durable local bytes + perceptual hashes exist for an inbound image message.
     *
     * @return array{bytes: string, mime: string, path: ?string, dhash: ?string, dct_hash: ?string}|null
     */
    public function ensureCached(ChannelMessage $message): ?array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return null;
        }

        $existing = $this->readLocalCache($message);
        if ($existing !== null) {
            return $existing;
        }

        $url = trim((string) ($message->media_url ?? ''));
        if ($url === '') {
            return null;
        }

        $downloaded = $this->downloadMediaBytes($url, $message->media_mime);
        if ($downloaded === null) {
            return null;
        }

        $path = $this->persistBytes($message, $downloaded['bytes'], $downloaded['mime']);
        $dhash = null;
        $dctHash = null;

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($downloaded['bytes']) >= $minBytes) {
            try {
                $dhash = $this->hasher->hashBinary($downloaded['bytes']);
                $dctHash = $this->hasher->dctHashBinary($downloaded['bytes']);
            } catch (Throwable $e) {
                Log::debug('Inbound media hash failed.', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message->forceFill([
            'media_path' => $path,
            'media_dhash' => $dhash,
            'media_dct_hash' => $dctHash,
            'media_mime' => $downloaded['mime'] ?: $message->media_mime,
        ])->save();

        return [
            'bytes' => $downloaded['bytes'],
            'mime' => $downloaded['mime'],
            'path' => $path,
            'dhash' => $dhash,
            'dct_hash' => $dctHash,
        ];
    }

    /**
     * @return array{bytes: string, mime: string, path: ?string, dhash: ?string, dct_hash: ?string}|null
     */
    public function readLocalCache(ChannelMessage $message): ?array
    {
        $path = trim((string) ($message->media_path ?? ''));
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
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

        $mime = (string) ($message->media_mime ?: mime_content_type($absolute) ?: 'image/jpeg');
        $dhash = is_string($message->media_dhash) && $message->media_dhash !== ''
            ? $message->media_dhash
            : null;
        $dctHash = is_string($message->media_dct_hash) && $message->media_dct_hash !== ''
            ? $message->media_dct_hash
            : null;

        if ($dhash === null || $dctHash === null) {
            try {
                if ($dhash === null) {
                    $dhash = $this->hasher->hashBinary($bytes);
                }
                if ($dctHash === null) {
                    $dctHash = $this->hasher->dctHashBinary($bytes);
                }
                $message->forceFill([
                    'media_dhash' => $dhash,
                    'media_dct_hash' => $dctHash,
                ])->save();
            } catch (Throwable $e) {
                Log::debug('Inbound media rehash failed.', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'bytes' => $bytes,
            'mime' => $mime,
            'path' => $relative,
            'dhash' => $dhash,
            'dct_hash' => $dctHash,
        ];
    }

    private function persistBytes(ChannelMessage $message, string $bytes, string $mime): string
    {
        $conversationId = (int) $message->channel_conversation_id;
        $directory = public_path('img/channel-inbound/'.$conversationId);
        File::ensureDirectoryExists($directory);

        $extension = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'jpg',
        };

        $filename = $message->id.'_'.substr(sha1($bytes), 0, 10).'.'.$extension;
        $absolute = $directory.DIRECTORY_SEPARATOR.$filename;
        File::put($absolute, $bytes);

        return 'img/channel-inbound/'.$conversationId.'/'.$filename;
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
            Log::debug('Inbound media download failed.', [
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
