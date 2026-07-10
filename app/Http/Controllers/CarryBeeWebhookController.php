<?php

namespace App\Http\Controllers;

use App\Services\Couriers\Webhooks\CarryBeeWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CarryBeeWebhookController extends Controller
{
    public function __invoke(Request $request, CarryBeeWebhookProcessor $processor): JsonResponse
    {
        if (! config('carrybee.webhook.enabled', true)) {
            return response()->json(['status' => 'disabled'], 503);
        }

        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        if (! isset($payload['event'])) {
            return response()->json(['error' => 'Missing event'], 422);
        }

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            Log::error('CarryBee webhook processing failed.', [
                'message' => $e->getMessage(),
                'event' => $payload['event'] ?? null,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('carrybee.webhook.secret');

        if (! $expected) {
            Log::warning('CarryBee webhook received but CARRYBEE_WEBHOOK_SECRET is not configured.');

            return false;
        }

        $signature = (string) $request->header('X-Carrybee-Webhook-Signature', '');

        return hash_equals($expected, $signature);
    }
}
