<?php

namespace App\Http\Controllers;

use App\Services\Sitemap\SitemapRebuildService;
use App\Services\Sitemap\SitemapXmlWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SitemapController extends Controller
{
    public function index(SitemapRebuildService $sitemaps, SitemapXmlWriter $writer): Response
    {
        $sitemaps->ensureFresh('lazy');

        $xml = $writer->readIndex();

        abort_unless($xml, 404);

        return $this->xml($xml);
    }

    public function child(string $file, SitemapXmlWriter $writer): Response
    {
        $xml = $writer->readChild($file);

        abort_unless($xml, 404);

        return $this->xml($xml);
    }

    public function rebuild(Request $request, SitemapRebuildService $sitemaps): Response
    {
        $token = (string) config('sitemap.rebuild_token');

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            throw new AccessDeniedHttpException('Invalid sitemap rebuild token.');
        }

        if ($active = $sitemaps->activeRun()) {
            $run = $sitemaps->runToCompletion($active);
        } else {
            $run = $sitemaps->runToCompletion($sitemaps->start('http'));
        }

        return response()->json([
            'ok' => $run->status === 'completed',
            'status' => $run->status,
            'message' => $run->message,
            'urls_written' => $run->urls_written,
            'progress_percent' => $run->progressPercent(),
            'error' => $run->error,
            'sitemap_url' => url('/sitemap.xml'),
        ], $run->status === 'completed' ? 200 : 500);
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
