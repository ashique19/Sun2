<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelMessageImageMatchController extends Controller
{
    /**
     * Match one inbound photo and persist the result. The inbox fires these in
     * parallel so a thread with many photos never blocks a Livewire request.
     *
     * Always answers with a final status (or 202 "busy" so the browser retries), never a
     * bare 500: errors and PHP fatals are stored on the message and shown to staff.
     */
    public function __invoke(ChannelMessage $message, ChannelMessageImageMatchService $matches): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        if (! $matches->isEligible($message)) {
            abort(404);
        }

        // Keep going if the browser times out, so the result is still saved for the next render.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $finished = false;
        register_shutdown_function(static function () use (&$finished, $message, $matches): void {
            $error = error_get_last();
            if ($finished || $error === null || ! in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            try {
                $matches->markFailed(
                    $message->refresh(),
                    ChannelMessageImageMatchService::failureReasonForFatal((string) $error['message']),
                    (string) $error['message'],
                );
            } catch (Throwable) {
            }
        });

        try {
            $message = $matches->resolve($message, waitSeconds: max(0, (int) config('channels.image_match.lock_wait_seconds', 10)));
        } catch (Throwable $e) {
            Log::warning('Inbox image match request failed.', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            try {
                $matches->markFailed($message->refresh(), 'server_error', $e->getMessage());
            } catch (Throwable $persistError) {
                Log::warning('Inbox image match failure could not be saved.', ['message_id' => $message->id, 'error' => $persistError->getMessage()]);
            }
        } finally {
            $finished = true;
        }

        $busy = $matches->awaitsMatch($message);
        $failure = $matches->failure($message);

        return response()->json([
            'message_id' => $message->id,
            'status' => $busy ? 'busy' : ($message->image_match_meta['status'] ?? null),
            'product_id' => $message->matched_product_id,
            'error' => $busy ? null : $failure['message'] ?? null,
        ], $busy ? 202 : 200);
    }
}
