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

    public function mount(ProductImageHashRebuildService $hashes): void
    {
        $this->activeRunId = $hashes->activeRun()?->id;
    }

    public function startRebuild(ProductImageHashRebuildService $hashes): void
    {
        $run = $hashes->start(
            trigger: 'admin',
            user: auth()->user(),
            force: $this->forceRehash,
            supersede: true,
        );

        $this->activeRunId = $run->id;
        $hashes->processChunk($run);
    }

    public function tickRebuild(ProductImageHashRebuildService $hashes): void
    {
        if (! $this->activeRunId) {
            $this->activeRunId = $hashes->activeRun()?->id;

            return;
        }

        $run = ImageHashRun::query()->find($this->activeRunId);

        if (! $run || ! $run->isActive()) {
            $this->activeRunId = null;

            return;
        }

        if ($hashes->processChunk($run)) {
            $this->activeRunId = null;
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
