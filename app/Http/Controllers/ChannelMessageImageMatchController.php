<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;

class ChannelMessageImageMatchController extends Controller
{
    /**
     * Match one inbound photo and persist the result. The inbox fires these in
     * parallel so a thread with many photos never blocks a Livewire request.
     */
    public function __invoke(ChannelMessage $message, ChannelMessageImageMatchService $matches): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        if (! $matches->isEligible($message)) {
            abort(404);
        }

        $message = $matches->resolve($message);

        return response()->json([
            'message_id' => $message->id,
            'status' => $message->image_match_meta['status'] ?? null,
            'product_id' => $message->matched_product_id,
        ]);
    }
}
