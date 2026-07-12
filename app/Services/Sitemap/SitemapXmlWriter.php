<?php

namespace App\Services\Sitemap;

use Illuminate\Support\Facades\Storage;

class SitemapXmlWriter
{
    public function diskPath(): string
    {
        return trim((string) config('sitemap.directory', 'sitemaps'), '/');
    }

    public function indexRelativePath(): string
    {
        return $this->diskPath().'/sitemap.xml';
    }

    public function childRelativePath(string $filename): string
    {
        return $this->diskPath().'/'.ltrim($filename, '/');
    }

    public function prepareDirectory(): void
    {
        Storage::disk('local')->makeDirectory($this->diskPath());
    }

    /**
     * @param  list<array{loc: string, lastmod?: string|null, changefreq?: string|null, priority?: string|null}>  $urls
     */
    public function writeUrlset(string $filename, array $urls): void
    {
        $xml = view('sitemap', ['urls' => $urls])->render();
        Storage::disk('local')->put($this->childRelativePath($filename), $this->normalizeXml($xml));
    }

    /**
     * @param  list<string>  $childFilenames
     */
    public function writeIndex(array $childFilenames): void
    {
        $sitemaps = [];

        foreach ($childFilenames as $filename) {
            $sitemaps[] = [
                'loc' => url('/sitemaps/'.$filename),
                'lastmod' => now()->toAtomString(),
            ];
        }

        $xml = view('sitemap-index', ['sitemaps' => $sitemaps])->render();
        Storage::disk('local')->put($this->indexRelativePath(), $this->normalizeXml($xml));
    }

    /**
     * @param  list<string>  $keepProductFiles
     */
    public function pruneStaleProductFiles(array $keepProductFiles): void
    {
        $keep = array_flip($keepProductFiles);
        $files = Storage::disk('local')->files($this->diskPath());

        foreach ($files as $path) {
            $basename = basename($path);

            if (! preg_match('/^products-\d+\.xml$/', $basename)) {
                continue;
            }

            if (! isset($keep[$basename])) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function readIndex(): ?string
    {
        if (! Storage::disk('local')->exists($this->indexRelativePath())) {
            return null;
        }

        return Storage::disk('local')->get($this->indexRelativePath());
    }

    public function readChild(string $filename): ?string
    {
        if (! preg_match('/^[a-z0-9.\-]+\.xml$/i', $filename)) {
            return null;
        }

        $path = $this->childRelativePath($filename);

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    private function normalizeXml(string $xml): string
    {
        return trim($xml)."\n";
    }
}
