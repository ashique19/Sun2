<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\AdminAccess;
use App\Support\ChannelMessageMediaType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChannelMessageMediaController extends Controller
{
    /**
     * Stream a channel attachment for staff preview.
     * Facebook CDN/Graph URLs often require a token, which browsers cannot send on <img>/<audio>.
     * Audio/video players also send Range requests; a 200 dump without ranges or with the
     * wrong MIME (Messenger labels AAC/MP4 voice as audio/mpeg) will not play.
     */
    public function __invoke(Request $request, ChannelMessage $message, FacebookPageTokenService $tokens): Response
    {
        AdminAccess::ensureStaffAdmin();

        if ($request->boolean('poster')) {
            $poster = $message->videoPosterUrl();
            abort_if(! is_string($poster) || $poster === '', 404);

            return $this->remoteFileResponse($poster, 'image/jpeg', $tokens->token(), audioAttachment: false);
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
                $tokens->token(),
                $message->isAudioAttachment(),
            );
        } catch (Throwable) {
            abort(404, 'Attachment could not be fetched.');
        }
    }

    private function remoteFileResponse(string $url, ?string $preferredMime, string $token, bool $audioAttachment): Response
    {
        $response = $this->fetchMedia($url, $token);

        if ($response === null || ! $response->successful()) {
            abort(404, 'Attachment could not be fetched.');
        }

        $bytes = $response->body();
        abort_if($bytes === '', 404, 'Attachment could not be fetched.');

        $contentType = ChannelMessageMediaType::detect(
            $bytes,
            $preferredMime ?: $response->header('Content-Type'),
            $audioAttachment,
        );

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
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $contentType = ChannelMessageMediaType::detect(
            $bytes,
            $mime ?: (mime_content_type($absolute) ?: null),
            $audioAttachment,
        );

        return $this->bytesResponse($bytes, $contentType);
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

        file_put_contents($tmp, $bytes);

        return response()->file($tmp, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ])->deleteFileAfterSend();
    }

    /**
     * @return \Illuminate\Http\Client\Response|null
     */
    private function fetchMedia(string $url, string $token)
    {
        $pending = Http::timeout(20)->withOptions(['allow_redirects' => true]);

        if ($token === '' || ! $this->urlNeedsToken($url)) {
            $response = $pending->get($url);

            return $response->successful() ? $response : null;
        }

        // Prefer Bearer (Graph / Messenger media)
        $response = Http::timeout(20)
            ->withOptions(['allow_redirects' => true])
            ->withToken($token)
            ->get($url);

        if ($response->successful()) {
            return $response;
        }

        // Some Messenger lookaside/CDN URLs expect access_token as a query param
        $withQuery = $this->withAccessTokenQuery($url, $token);
        if ($withQuery !== $url) {
            $response = Http::timeout(20)
                ->withOptions(['allow_redirects' => true])
                ->get($withQuery);

            if ($response->successful()) {
                return $response;
            }
        }

        return null;
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
