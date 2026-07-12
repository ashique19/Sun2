<?php

namespace App\Livewire\Admin;

use App\Models\SitemapRun;
use App\Services\Sitemap\SitemapRebuildService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sitemap')]
#[Layout('components.layouts.admin')]
class AdminSitemap extends Component
{
    public ?int $activeRunId = null;

    public function mount(SitemapRebuildService $sitemaps): void
    {
        $this->activeRunId = $sitemaps->activeRun()?->id;
    }

    public function startRebuild(SitemapRebuildService $sitemaps): void
    {
        $run = $sitemaps->start('admin', auth()->user(), force: true);
        $this->activeRunId = $run->id;
        $sitemaps->processChunk($run);
    }

    public function tickRebuild(SitemapRebuildService $sitemaps): void
    {
        if (! $this->activeRunId) {
            $this->activeRunId = $sitemaps->activeRun()?->id;

            return;
        }

        $run = SitemapRun::query()->find($this->activeRunId);

        if (! $run || ! $run->isActive()) {
            $this->activeRunId = null;

            return;
        }

        $done = $sitemaps->processChunk($run);

        if ($done) {
            $this->activeRunId = null;
        }
    }

    public function render(SitemapRebuildService $sitemaps)
    {
        $latest = $sitemaps->latestRun();
        $active = $sitemaps->activeRun();

        if ($active) {
            $this->activeRunId = $active->id;
        }

        return view('livewire.admin.admin-sitemap', [
            'isDirty' => $sitemaps->isDirty(),
            'dirtyReason' => $sitemaps->dirtyReason(),
            'indexExists' => $sitemaps->indexExists(),
            'latest' => $latest,
            'active' => $active,
            'recentRuns' => SitemapRun::query()
                ->with('triggeredBy:id,name')
                ->latest('id')
                ->limit(15)
                ->get(),
            'sitemapUrl' => url('/sitemap.xml'),
            'rebuildUrlHint' => url('/internal/sitemap/rebuild?token=YOUR_TOKEN'),
            'tokenConfigured' => filled(config('sitemap.rebuild_token')),
        ]);
    }
}
