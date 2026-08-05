<?php

namespace App\Http\Controllers;

use App\Services\Channels\MessengerConversationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MessengerConversationSyncController extends Controller
{
    public function __invoke(Request $request, MessengerConversationSyncService $sync): JsonResponse
    {
        $token = (string) config('facebook.messenger.sync_token');

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            throw new AccessDeniedHttpException('Invalid Messenger conversation sync token.');
        }

        $result = $sync->sync(
            conversationLimit: max(1, min(100, (int) $request->query('conversations', 50))),
            messagesPerThread: max(1, min(50, (int) $request->query('messages', 30))),
        );

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'conversations' => $result['conversations'],
            'messages' => $result['messages'],
            'graph_threads' => $result['graph_threads'],
        ], $result['ok'] ? 200 : 500);
    }
}
