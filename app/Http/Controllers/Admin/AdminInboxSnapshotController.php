<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelConversation;
use App\Services\Admin\InboxSnapshotBuilder;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInboxSnapshotController extends Controller
{
    /**
     * Slim thread JSON for the inbox local cache.
     *
     * Used to prefetch conversations while the inbox tab is open so the next
     * click can paint from localStorage, then Livewire confirms from the DB.
     */
    public function __invoke(Request $request, InboxSnapshotBuilder $snapshots): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:8'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));
        $ids = array_slice($ids, 0, 8);

        $conversations = ChannelConversation::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $threads = [];
        foreach ($ids as $id) {
            $conversation = $conversations->get($id);
            if (! $conversation) {
                continue;
            }

            $threads[] = $snapshots->thread($conversation);
        }

        return response()->json([
            'user_id' => (int) auth()->id(),
            'threads' => $threads,
        ]);
    }
}
