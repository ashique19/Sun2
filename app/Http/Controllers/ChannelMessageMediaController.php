<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Channels\ChannelInboundMediaService;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\AdminAccess;
use App\Support\ChannelMessageMediaType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChannelMessageMediaController extends Controller
{
    /**
     * Stream a channel attachment for staff preview.
     * Facebook CDN/Graph URLs often require a token, which browsers cannot send on <img>/<audio>.
     * Audio/video players also send Range requests; a 200 dump without ranges or with the
     * wrong MIME (Messenger labels AAC/MP4 voice as audio/mpeg) will not play.
     * Messenger lookaside URLs also expire / can be one-shot, so persist voice locally
     * after the first successful fetch.
     */
    public function __construct(
        private ChannelInboundMediaService $inboundMedia,
        private FacebookPageTokenService $tokens,
    ) {}

    public function __invoke(Request $request, ChannelMessage $message): Response
    {
        AdminAccess::ensureStaffAdmin();

        if ($request->boolean('poster')) {
            $poster = $message->videoPosterUrl();
            abort_if(! is_string($poster) || $poster === '', 404);

            return $this->remoteFileResponse($poster, 'image/jpeg', audioAttachment: false);
        }

        $localPath = $message->localMediaPath();
        if ($localPath !== null) {
            $local = $this->localFileResponse($localPath, $message->media_mime, $message->isAudioAttachment());
            if ($local) {
                return $local;
            }
        }

        $url = trim((string) ($message->media_url ?? ''));
        abort_if($url === '', 404);

        try {
            if ($local = $this->localFileResponse($url, $message->media_mime, $message->isAudioAttachment())) {
                return $local;
            }

            return $this->remoteFileResponse(
                $url,
                $message->media_mime,
                $message->isAudioAttachment(),
                $message,
            );
        } catch (Throwable) {
            abort(404, 'Attachment could not be fetched.');
        }
    }

    private function remoteFileResponse(
        string $url,
        ?string $preferredMime,
        bool $audioAttachment,
        ?ChannelMessage $persistTo = null,
    ): Response {
        $token = $this->tokens->token();
        $response = $this->fetchMedia($url, $token);

        if (! $this->usableHttpMedia($response) && $persistTo !== null) {
            $refreshed = $this->refreshFacebookUrl($persistTo, $token);
            if (is_string($refreshed) && $refreshed !== '' && $refreshed !== $url) {
                $response = $this->fetchMedia($refreshed, $token);
            }
        }

        if (! $this->usableHttpMedia($response)) {
            abort(404, 'Attachment could not be fetched.');
        }

        $bytes = $response->body();
        $contentType = ChannelMessageMediaType::detect(
            $bytes,
            $preferredMime ?: $response->header('Content-Type'),
            $audioAttachment,
        );

        if ($persistTo !== null && (str_starts_with($contentType, 'audio/') || str_starts_with($contentType, 'video/'))) {
            $path = $this->inboundMedia->persistPlayable($persistTo, $bytes, $contentType);
            if ($path) {
                $local = $this->localFileResponse($path, $contentType, $audioAttachment);
                if ($local) {
                    return $local;
                }
            }
        }

        return $this->bytesResponse($bytes, $contentType);
    }

    private function localFileResponse(string $url, ?string $mime, bool $audioAttachment): ?Response
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $url), '/');
        if (str_contains($relative, '..')) {
            return null;
        }

        $absolute = public_path($relative);
        if (! is_file($absolute)) {
            return null;
        }

        $bytes = file_get_contents($absolute);
        if ($bytes === false || $bytes === '' || ! ChannelMessageMediaType::isUsableMedia($bytes)) {
            return null;
        }

        $contentType = ChannelMessageMediaType::detect(
            $bytes,
            $mime ?: (mime_content_type($absolute) ?: null),
            $audioAttachment,
        );

        return $this->fileResponse($absolute, $contentType, deleteAfterSend: false);
    }

    private function bytesResponse(string $bytes, string $contentType): Response
    {
        if (! str_starts_with($contentType, 'audio/') && ! str_starts_with($contentType, 'video/')) {
            return response($bytes, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'inbox-media-');
        if ($tmp === false) {
            abort(404, 'Attachment could not be fetched.');
        }

        $named = $tmp.'.'.ChannelMessageMediaType::extension($contentType);
        if (@rename($tmp, $named)) {
            $tmp = $named;
        }

        file_put_contents($tmp, $bytes);

        return $this->fileResponse($tmp, $contentType, deleteAfterSend: true);
    }

    private function fileResponse(string $path, string $contentType, bool $deleteAfterSend): Response
    {
        if (! str_starts_with($contentType, 'audio/') && ! str_starts_with($contentType, 'video/')) {
            $bytes = file_get_contents($path);
            abort_if($bytes === false || $bytes === '', 404, 'Attachment could not be fetched.');

            return response($bytes, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store, no-transform',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'X-Accel-Buffering' => 'no',
        ], false);

        $response->setContentDisposition(
            'inline',
            ChannelMessageMediaType::filename($contentType),
        );
        $response->headers->set('Content-Type', $contentType);

        if ($deleteAfterSend) {
            $response->deleteFileAfterSend();
        }

        return $response;
    }

    /**
     * @return \Illuminate\Http\Client\Response|null
     */
    private function fetchMedia(string $url, string $token)
    {
        $pending = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'InboxMedia/1.0'])
            ->withOptions(['allow_redirects' => true]);

        if ($token === '' || ! $this->urlNeedsToken($url)) {
            $response = $pending->get($url);

            return $this->usableHttpMedia($response) ? $response : null;
        }

        // Prefer Bearer (Graph / Messenger media)
        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'InboxMedia/1.0'])
            ->withOptions(['allow_redirects' => true])
            ->withToken($token)
            ->get($url);

        if ($this->usableHttpMedia($response)) {
            return $response;
        }

        // Some Messenger lookaside/CDN URLs expect access_token as a query param
        $withQuery = $this->withAccessTokenQuery($url, $token);
        if ($withQuery !== $url) {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'InboxMedia/1.0'])
                ->withOptions(['allow_redirects' => true])
                ->get($withQuery);

            if ($this->usableHttpMedia($response)) {
                return $response;
            }
        }

        return null;
    }

    private function refreshFacebookUrl(ChannelMessage $message, string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        $mid = trim((string) ($message->external_message_id ?? ''));
        if ($mid === '') {
            return null;
        }

        $mid = explode('#', $mid, 2)[0];
        $version = $this->tokens->graphVersion();

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'InboxMedia/1.0'])
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$mid, [
                    'fields' => 'attachments{mime_type,file_url,image_data{url},video_data{url}}',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $attachments = $response->json('attachments.data');
        if (! is_array($attachments)) {
            return null;
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $url = data_get($attachment, 'file_url')
                ?? data_get($attachment, 'image_data.url')
                ?? data_get($attachment, 'video_data.url');

            if (! is_string($url) || $url === '') {
                continue;
            }

            $updates = ['media_url' => $url];
            $mime = data_get($attachment, 'mime_type');
            if (is_string($mime) && $mime !== '') {
                $updates['media_mime'] = $mime;
            }

            $message->forceFill($updates)->save();

            return $url;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Http\Client\Response|null  $response
     */
    private function usableHttpMedia($response): bool
    {
        if ($response === null || ! $response->successful()) {
            return false;
        }

        $bytes = $response->body();

        return $bytes !== '' && ChannelMessageMediaType::isUsableMedia($bytes);
    }

    private function urlNeedsToken(string $url): bool
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
