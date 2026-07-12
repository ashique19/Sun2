<?php

namespace App\Services\Sitemap;

/**
 * Plain PHP file I/O for sitemap XML.
 * Avoids Laravel Storage/Flysystem so rebuilds still work if fileinfo is disabled
 * (Storage falls back via the polyfill, but this path stays independent).
 */
class SitemapXmlWriter
{
    public function diskPath(): string
    {
        return trim((string) config('sitemap.directory', 'sitemaps'), '/');
    }

    public function absoluteDirectory(): string
    {
        return storage_path('app/'.$this->diskPath());
    }

    public function indexAbsolutePath(): string
    {
        return $this->absoluteDirectory().DIRECTORY_SEPARATOR.'sitemap.xml';
    }

    public function childAbsolutePath(string $filename): string
    {
        return $this->absoluteDirectory().DIRECTORY_SEPARATOR.ltrim($filename, '/\\');
    }

    /** @deprecated Prefer absolute helpers; kept for callers that only need the relative key. */
    public function indexRelativePath(): string
    {
        return $this->diskPath().'/sitemap.xml';
    }

    public function prepareDirectory(): void
    {
        $dir = $this->absoluteDirectory();

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create sitemap directory: '.$dir);
        }
    }

    /**
     * @param  list<array{loc: string, lastmod?: string|null, changefreq?: string|null, priority?: string|null}>  $urls
     */
    public function writeUrlset(string $filename, array $urls): void
    {
        $this->prepareDirectory();
        $xml = view('sitemap', ['urls' => $urls])->render();
        $this->writeFile($this->childAbsolutePath($filename), $this->normalizeXml($xml));
    }

    /**
     * @param  list<string>  $childFilenames
     */
    public function writeIndex(array $childFilenames): void
    {
        $this->prepareDirectory();

        $sitemaps = [];

        foreach ($childFilenames as $filename) {
            $sitemaps[] = [
                'loc' => url('/sitemaps/'.$filename),
                'lastmod' => now()->toAtomString(),
            ];
        }

        $xml = view('sitemap-index', ['sitemaps' => $sitemaps])->render();
        $this->writeFile($this->indexAbsolutePath(), $this->normalizeXml($xml));
    }

    /**
     * @param  list<string>  $keepProductFiles
     */
    public function pruneStaleProductFiles(array $keepProductFiles): void
    {
        $keep = array_flip($keepProductFiles);
        $dir = $this->absoluteDirectory();

        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'products-*.xml') ?: [] as $path) {
            $basename = basename($path);

            if (! isset($keep[$basename])) {
                @unlink($path);
            }
        }
    }

    public function indexExists(): bool
    {
        return is_file($this->indexAbsolutePath());
    }

    public function readIndex(): ?string
    {
        $path = $this->indexAbsolutePath();

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function readChild(string $filename): ?string
    {
        if (! preg_match('/^[a-z0-9.\-]+\.xml$/i', $filename)) {
            return null;
        }

        $path = $this->childAbsolutePath($filename);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write sitemap file: '.$path);
        }
    }

    private function normalizeXml(string $xml): string
    {
        return trim($xml)."\n";
    }
}
