<?php

namespace App\Livewire\Admin;

use App\Models\ImageHashRun;
use App\Services\Admin\ProductImageHashRebuildService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Image Hashes')]
#[Layout('components.layouts.admin')]
class AdminProductImageHashes extends Component
{
    public ?int $activeRunId = null;

    public bool $forceRehash = false;

    public bool $rebuildModalOpen = false;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(ProductImageHashRebuildService $hashes): void
    {
        $this->activeRunId = $hashes->activeRun()?->id;
    }

    public function dismissStatusMessage(): void
    {
        $this->statusMessage = null;
    }

    public function dismissErrorMessage(): void
    {
        $this->errorMessage = null;
    }

    public function openRebuildModal(ProductImageHashRebuildService $hashes): void
    {
        if ($hashes->activeRun()) {
            return;
        }

        $this->forceRehash = false;

        $this->rebuildModalOpen = true;
    }

    public function closeRebuildModal(): void
    {
        $this->rebuildModalOpen = false;
    }

    public function confirmRebuild(ProductImageHashRebuildService $hashes): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        if ($hashes->activeRun()) {
            $this->rebuildModalOpen = false;
            $this->errorMessage = 'A rebuild is already in progress. Keep this tab open until it finishes.';

            return;
        }

        $run = $hashes->start(
            trigger: 'admin',
            user: auth()->user(),
            force: $this->forceRehash,
            supersede: true,
        );

        $this->activeRunId = $run->id;
        $this->rebuildModalOpen = false;

        if ((int) $run->progress_total === 0) {
            $hashes->processChunk($run->fresh());
            $this->syncRunFeedback($run->fresh());
            $this->activeRunId = $hashes->activeRun()?->id;
        }
    }

    public function startRebuild(ProductImageHashRebuildService $hashes): void
    {
        $this->openRebuildModal($hashes);
    }

    public function tickRebuild(ProductImageHashRebuildService $hashes): void
    {
        if (! $this->activeRunId) {
            $this->activeRunId = $hashes->activeRun()?->id;

            return;
        }

        $run = ImageHashRun::query()->find($this->activeRunId);

        if (! $run || ! $run->isActive()) {
            if ($run) {
                $this->syncRunFeedback($run);
            }

            $this->activeRunId = $hashes->activeRun()?->id;

            return;
        }

        if ($hashes->processChunk($run)) {
            $this->syncRunFeedback($run->fresh());
            $this->activeRunId = $hashes->activeRun()?->id;
        }
    }

    private function syncRunFeedback(ImageHashRun $run): void
    {
        if ($run->status === 'completed') {
            $this->statusMessage = $run->message ?: 'Rebuild finished.';
            $this->errorMessage = null;

            return;
        }

        if ($run->status === 'failed') {
            $this->errorMessage = $run->error ?: $run->message ?: 'Rebuild failed.';
            $this->statusMessage = null;
        }
    }

    public function render(ProductImageHashRebuildService $hashes)
    {
        $latest = $hashes->latestRun();
        $active = $hashes->activeRun();

        if ($active) {
            $this->activeRunId = $active->id;
        }

        return view('livewire.admin.admin-product-image-hashes', [
            'coverage' => $hashes->coverage(),
            'latest' => $latest,
            'active' => $active,
            'recentRuns' => ImageHashRun::query()
                ->with('triggeredBy:id,name')
                ->latest('id')
                ->limit(15)
                ->get(),
            'rebuildUrlHint' => url('/internal/product-image-hashes/rebuild?token=YOUR_TOKEN'),
            'tokenConfigured' => filled(config('products.image_hash_rebuild_token')),
            'gdAvailable' => extension_loaded('gd'),
        ]);
    }
}
