<?php

namespace App\Livewire\Admin;

use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Services\Social\MetaGraphSocialPublisher;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminSocialPostsShow extends Component
{
    public SocialPost $socialPost;

    public ?string $message = null;

    /** idle | publishing | done */
    public string $republishPhase = 'idle';

    /**
     * @var array<string, array{label: string, status: string, error: ?string, url: ?string}>
     */
    public array $channelProgress = [];

    public function mount(SocialPost $socialPost): void
    {
        $this->socialPost = $socialPost;
    }

    /**
     * @return list<string>
     */
    public function selectedChannels(): array
    {
        return [SocialPostPublication::CHANNEL_FACEBOOK];
    }

    public function startRepublish(): void
    {
        $this->ensureCanRepublish();

        if ($this->republishPhase === 'publishing') {
            return;
        }

        $channels = $this->selectedChannels();
        $this->message = null;
        $this->channelProgress = [];

        foreach ($channels as $channel) {
            $this->channelProgress[$channel] = [
                'label' => $this->channelLabel($channel),
                'status' => 'waiting',
                'error' => null,
                'url' => null,
            ];
        }

        $this->republishPhase = 'publishing';
    }

    public function markChannelPosting(string $channel): void
    {
        if ($this->republishPhase !== 'publishing' || ! isset($this->channelProgress[$channel])) {
            return;
        }

        if (! in_array($this->channelProgress[$channel]['status'], ['waiting', 'posting'], true)) {
            return;
        }

        $this->channelProgress[$channel]['status'] = 'posting';
        $this->channelProgress[$channel]['error'] = null;
    }

    public function publishSelectedChannel(string $channel, MetaGraphSocialPublisher $publisher): void
    {
        if ($this->republishPhase !== 'publishing' || ! isset($this->channelProgress[$channel])) {
            return;
        }

        if (! in_array($this->channelProgress[$channel]['status'], ['waiting', 'posting'], true)) {
            return;
        }

        $this->ensureCanRepublish();
        $this->channelProgress[$channel]['status'] = 'posting';

        $post = $this->socialPost->fresh(['products']);
        if (! $post) {
            $this->channelProgress[$channel] = [
                'label' => $this->channelLabel($channel),
                'status' => 'failed',
                'error' => 'Social post was not found.',
                'url' => null,
            ];
            $this->finishIfComplete();

            return;
        }

        $publication = $publisher->publishChannel($post, $channel);

        $this->channelProgress[$channel] = [
            'label' => $this->channelLabel($channel),
            'status' => $publication->status === SocialPostPublication::STATUS_SUCCESS ? 'success' : 'failed',
            'error' => $publication->error,
            'url' => $publication->external_url,
        ];

        $this->socialPost->refresh();
        $this->finishIfComplete();
    }

    /**
     * Backward-compatible: re-publish Facebook in one request.
     */
    public function republish(MetaGraphSocialPublisher $publisher): void
    {
        $this->startRepublish();

        if ($this->republishPhase !== 'publishing') {
            return;
        }

        foreach (array_keys($this->channelProgress) as $channel) {
            $this->publishSelectedChannel($channel, $publisher);
        }
    }

    private function finishIfComplete(): void
    {
        foreach ($this->channelProgress as $row) {
            if (! in_array($row['status'], ['success', 'failed'], true)) {
                return;
            }
        }

        $this->republishPhase = 'done';
        $this->message = 'Re-publish finished.';
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            SocialPostPublication::CHANNEL_FACEBOOK => 'Facebook',
            SocialPostPublication::CHANNEL_INSTAGRAM => 'Instagram',
            default => ucfirst($channel),
        };
    }

    private function ensureCanRepublish(): void
    {
        abort_unless(auth()->check() && auth()->user()->hasAnyRole(['admin', 'dev']), 403);
    }

    public function render()
    {
        $post = SocialPost::query()
            ->with([
                'publications' => fn ($q) => $q->latest('id'),
                'products' => fn ($q) => $q->with([
                    'category:id,slug,name',
                    'images' => fn ($img) => $img->orderBy('sort_order'),
                ])->orderBy('social_post_products.sort_order'),
            ])
            ->findOrFail($this->socialPost->id);

        return view('livewire.admin.admin-social-post-show', [
            'post' => $post,
        ]);
    }
}
