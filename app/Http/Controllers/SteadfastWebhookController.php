<?php

namespace App\Http\Controllers;

use App\Services\Couriers\SteadfastWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SteadfastWebhookController extends Controller
{
    public function __invoke(Request $request, SteadfastWebhookProcessor $processor): JsonResponse
    {
        if (! config('steadfast.webhook.enabled', true)) {
            return response()->json(['status' => 'disabled'], 503);
        }

        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        if (! isset($payload['notification_type'])) {
            return response()->json(['error' => 'Missing notification_type'], 422);
        }

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            Log::error('Steadfast webhook processing failed.', [
                'message' => $e->getMessage(),
                'invoice' => $payload['invoice'] ?? null,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('steadfast.webhook.token');

        if (! $expected) {
            Log::warning('Steadfast webhook received but STEADFAST_WEBHOOK_TOKEN is not configured.');

            return false;
        }

        $authorization = (string) $request->header('Authorization', '');

        if ($authorization === 'Bearer '.$expected) {
            return true;
        }

        $alternate = (string) $request->header('X-Steadfast-Token', '');

        return hash_equals($expected, $alternate);
    }
}
