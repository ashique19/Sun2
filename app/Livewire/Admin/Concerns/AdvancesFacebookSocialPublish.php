<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Services\Social\MetaGraphSocialPublisher;
use Throwable;

trait AdvancesFacebookSocialPublish
{
    /**
     * Unpublished Facebook photo ids collected across Livewire requests.
     *
     * @var list<string>
     */
    public array $albumMediaIds = [];

    public int $albumCursor = 0;

    public int $albumTotal = 0;

    abstract protected function publishingPost(): ?SocialPost;

    abstract protected function isChannelPublishActive(): bool;

    abstract protected function finishIfComplete(): void;

    abstract protected function channelLabel(string $channel): string;

    protected function resetAlbumProgress(): void
    {
        $this->albumMediaIds = [];
        $this->albumCursor = 0;
        $this->albumTotal = 0;
    }

    /**
     * One Graph step per request so LiteSpeed connection timeout cannot kill a 12-photo album.
     */
    public function advanceSelectedChannel(string $channel, MetaGraphSocialPublisher $publisher): void
    {
        if (! $this->isChannelPublishActive() || ! isset($this->channelProgress[$channel])) {
            return;
        }

        if (! in_array($this->channelProgress[$channel]['status'], ['waiting', 'posting'], true)) {
            return;
        }

        $this->channelProgress[$channel]['status'] = 'posting';
        $this->channelProgress[$channel]['error'] = null;

        $post = $this->publishingPost();
        if (! $post) {
            $this->failChannel($channel, 'Social post was not found.');

            return;
        }

        if ($channel !== SocialPostPublication::CHANNEL_FACEBOOK) {
            $this->recordChannelPublication($channel, $publisher->publishChannel($post, $channel));

            return;
        }

        try {
            $items = $publisher->facebookPublishMediaItems($post);
            $this->albumTotal = count($items);

            if ($items === []) {
                $this->recordChannelPublication($channel, $publisher->publishChannel($post, $channel));

                return;
            }

            $singlePhoto = (string) $post->layout === SocialPost::LAYOUT_COLLAGE && count($items) === 1;

            if ($singlePhoto) {
                $this->recordChannelPublication($channel, $publisher->publishChannel($post, $channel));

                return;
            }

            if ($this->albumCursor < count($items)) {
                $mediaId = $publisher->uploadFacebookUnpublishedPhoto($items[$this->albumCursor]);
                $this->albumMediaIds[] = $mediaId;
                $this->albumCursor++;
                $this->channelProgress[$channel]['detail'] = 'Uploading photo '.$this->albumCursor.' of '.count($items).'…';

                return;
            }

            $this->recordChannelPublication(
                $channel,
                $publisher->completeFacebookAlbum($post, $this->albumMediaIds),
            );
        } catch (Throwable $e) {
            $this->recordChannelPublication(
                $channel,
                $publisher->failChannelAttempt($post, $channel, $e->getMessage()),
            );
        }
    }

    /**
     * Finish every remaining Graph step in this request (tests / older callers).
     */
    protected function publishSelectedChannelUntilDone(string $channel, MetaGraphSocialPublisher $publisher): void
    {
        $guard = 0;

        while ($guard < 80) {
            $guard++;
            $status = $this->channelProgress[$channel]['status'] ?? '';

            if (in_array($status, ['success', 'failed'], true)) {
                return;
            }

            $this->advanceSelectedChannel($channel, $publisher);
        }

        if (! in_array($this->channelProgress[$channel]['status'] ?? '', ['success', 'failed'], true)) {
            $this->failChannel($channel, 'Publishing timed out before every photo was uploaded.');
        }
    }

    protected function recordChannelPublication(string $channel, SocialPostPublication $publication): void
    {
        $this->channelProgress[$channel] = [
            'label' => $this->channelLabel($channel),
            'status' => $publication->status === SocialPostPublication::STATUS_SUCCESS ? 'success' : 'failed',
            'error' => $publication->error,
            'url' => $publication->external_url,
            'detail' => null,
        ];

        $this->resetAlbumProgress();
        $this->finishIfComplete();
    }

    protected function failChannel(string $channel, string $error): void
    {
        $this->channelProgress[$channel] = [
            'label' => $this->channelLabel($channel),
            'status' => 'failed',
            'error' => $error,
            'url' => null,
            'detail' => null,
        ];

        $this->resetAlbumProgress();
        $this->finishIfComplete();
    }
}
