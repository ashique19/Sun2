<?php

namespace App\Http\Controllers;

use App\Services\Couriers\Webhooks\RedxWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RedxWebhookController extends Controller
{
    public function __invoke(Request $request, RedxWebhookProcessor $processor): JsonResponse
    {
        if (! config('redx.webhook.enabled', true)) {
            return response()->json(['status' => 'disabled'], 503);
        }

        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        if (! isset($payload['status'])) {
            return response()->json(['error' => 'Missing status'], 422);
        }

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            Log::error('RedX webhook processing failed.', [
                'message' => $e->getMessage(),
                'tracking_number' => $payload['tracking_number'] ?? null,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('redx.webhook.token');

        if (! $expected) {
            Log::warning('RedX webhook received but REDX_WEBHOOK_TOKEN is not configured.');

            return false;
        }

        $queryToken = (string) $request->query('token', '');

        if ($queryToken !== '' && hash_equals($expected, $queryToken)) {
            return true;
        }

        $header = (string) $request->header(config('redx.webhook.secret_header', 'X-Redx-Webhook-Secret'), '');

        return hash_equals($expected, $header);
    }
}
