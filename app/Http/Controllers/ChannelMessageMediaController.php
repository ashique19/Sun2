<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\AdminAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class ChannelMessageMediaController extends Controller
{
    /**
     * Stream a channel attachment for staff preview.
     * Facebook/WhatsApp CDN/Graph URLs often require a token, which browsers cannot send on <img>.
     */
    public function __invoke(ChannelMessage $message, FacebookPageTokenService $tokens): Response
    {
        AdminAccess::ensureStaffAdmin();

        $url = trim((string) ($message->media_url ?? ''));
        abort_if($url === '', 404);

        try {
            if ($local = $this->localFileResponse($url, $message->media_mime)) {
                return $local;
            }

            $response = $this->fetchMedia($url, $this->tokenForUrl($url, $tokens));

            if ($response === null || ! $response->successful()) {
                abort(404, 'Attachment could not be fetched.');
            }

            $contentType = (string) (
                $message->media_mime
                ?: $response->header('Content-Type')
                ?: 'application/octet-stream'
            );
            $contentType = explode(';', $contentType)[0];

            return response($response->body(), 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable) {
            abort(404, 'Attachment could not be fetched.');
        }
    }

    private function localFileResponse(string $url, ?string $mime): ?Response
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

        $contentType = $mime ?: (mime_content_type($absolute) ?: 'application/octet-stream');
        $contentType = explode(';', (string) $contentType)[0];

        return response(file_get_contents($absolute), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function tokenForUrl(string $url, FacebookPageTokenService $tokens): string
    {
        if (str_contains($url, 'whatsapp.net') || str_contains($url, 'mmg.whatsapp')) {
            $whatsappToken = trim((string) config('whatsapp.access_token', ''));

            return $whatsappToken !== '' ? $whatsappToken : $tokens->token();
        }

        return $tokens->token();
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

        // Prefer Bearer (Graph / WhatsApp media)
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
            || str_contains($url, 'lookaside')
            || str_contains($url, 'whatsapp.net')
            || str_contains($url, 'mmg.whatsapp');
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
