<?php

namespace App\Http\Controllers;

use App\Services\Couriers\Webhooks\PathaoWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PathaoWebhookController extends Controller
{
    public function __invoke(Request $request, PathaoWebhookProcessor $processor): JsonResponse
    {
        if (! config('pathao.webhook.enabled', true)) {
            return $this->respond(['status' => 'disabled'], 503);
        }

        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            Log::error('Pathao webhook processing failed.', [
                'message' => $e->getMessage(),
                'event' => $payload['event'] ?? null,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return $this->respond(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function respond(array $body, int $status = 200): JsonResponse
    {
        $response = response()->json($body, $status);

        $secret = config('pathao.webhook.integration_secret') ?: config('pathao.webhook.secret');

        if ($secret) {
            $response->header('X-Pathao-Merchant-Webhook-Integration-Secret', $secret);
        }

        return $response;
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('pathao.webhook.secret');

        if (! $expected) {
            Log::warning('Pathao webhook received but PATHAO_WEBHOOK_SECRET is not configured.');

            return false;
        }

        $signature = (string) $request->header('X-PATHAO-Signature', '');

        if (hash_equals($expected, $signature)) {
            return true;
        }

        $alternate = (string) $request->header('X-Pathao-Signature', '');

        return hash_equals($expected, $alternate);
    }
}
