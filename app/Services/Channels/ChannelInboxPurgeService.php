<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelInboxPurgeService
{
    public function __construct(
        private ChannelOrderDraftService $drafts,
    ) {}

    /**
     * Delete conversations inactive longer than the retention window.
     *
     * @return array{purged: int, drafts_discarded: int, cutoff: string}
     */
    public function purge(?int $retentionDays = null, bool $dryRun = false): array
    {
        $days = max(1, $retentionDays ?? (int) config('channels.inbox.retention_days', 7));
        $cutoff = now()->subDays($days);

        $lock = Cache::lock('channel-inbox-purge', 120);

        if (! $lock->get()) {
            return [
                'purged' => 0,
                'drafts_discarded' => 0,
                'cutoff' => $cutoff->toIso8601String(),
            ];
        }

        try {
            return $this->runPurge($cutoff, $dryRun);
        } finally {
            $lock->release();
        }
    }

    /**
     * Throttled purge for Admin Inbox page loads.
     *
     * @return array{purged: int, drafts_discarded: int, cutoff: string}|null
     */
    public function purgeOnInboxLoad(): ?array
    {
        if (! (bool) config('channels.inbox.purge_on_inbox_load', true)) {
            return null;
        }

        // Avoid repeating work on rapid Livewire remounts in the same minute.
        $throttleKey = 'channel-inbox-purge-on-load';
        if (! Cache::add($throttleKey, 1, now()->addMinute())) {
            return null;
        }

        return $this->purge();
    }

    /**
     * @return array{purged: int, drafts_discarded: int, cutoff: string}
     */
    private function runPurge(\DateTimeInterface $cutoff, bool $dryRun): array
    {
        $purged = 0;
        $draftsDiscarded = 0;

        $ids = ChannelConversation::query()
            ->whereRaw(
                'COALESCE(last_inbound_at, last_outbound_at, created_at) < ?',
                [$cutoff],
            )
            ->orderBy('id')
            ->pluck('id');

        if ($dryRun) {
            return [
                'purged' => $ids->count(),
                'drafts_discarded' => 0,
                'cutoff' => Carbon::parse($cutoff)->toIso8601String(),
            ];
        }

        foreach ($ids as $id) {
            $conversation = ChannelConversation::query()->find($id);
            if (! $conversation) {
                continue;
            }

            $result = $this->purgeConversation($conversation);
            $purged += $result['purged'] ? 1 : 0;
            $draftsDiscarded += $result['drafts_discarded'];
        }

        return [
            'purged' => $purged,
            'drafts_discarded' => $draftsDiscarded,
            'cutoff' => Carbon::parse($cutoff)->toIso8601String(),
        ];
    }

    /**
     * @return array{purged: bool, drafts_discarded: int}
     */
    private function purgeConversation(ChannelConversation $conversation): array
    {
        $conversationId = (int) $conversation->id;
        $draftsDiscarded = 0;

        try {
            $draftIds = Order::query()
                ->where('status', Order::STATUS_DRAFT)
                ->where(function ($q) use ($conversation) {
                    $q->where('channel_conversation_id', $conversation->id);

                    if ($conversation->draft_order_id) {
                        $q->orWhere('id', $conversation->draft_order_id);
                    }
                })
                ->pluck('id');

            foreach ($draftIds as $draftId) {
                $draft = Order::query()->find($draftId);
                if ($draft && $draft->isAiDraft()) {
                    $this->drafts->discard($draft);
                    $draftsDiscarded++;
                }
            }

            $conversation->refresh();

            if ($conversation->exists) {
                if ($conversation->draft_order_id) {
                    $conversation->forceFill(['draft_order_id' => null])->save();
                }

                $conversation->delete();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to purge channel conversation.', [
                'conversation_id' => $conversationId,
                'message' => $e->getMessage(),
            ]);

            return ['purged' => false, 'drafts_discarded' => $draftsDiscarded];
        }

        $replyDir = public_path('img/channel-replies/'.$conversationId);
        if (is_dir($replyDir)) {
            File::deleteDirectory($replyDir);
        }

        return ['purged' => true, 'drafts_discarded' => $draftsDiscarded];
    }
}
