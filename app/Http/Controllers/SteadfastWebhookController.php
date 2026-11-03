<?php

namespace App\Http\Controllers;

use App\Services\Couriers\SteadfastWebhookPayload;
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

        $events = SteadfastWebhookPayload::eventsFrom($this->requestPayload($request));

        if ($events === []) {
            Log::warning('Steadfast webhook: empty or unusable payload.');

            // Steadfast never retries 4xx. Ack empty bodies so a parser miss is not fatal.
            return response()->json(['status' => 'success']);
        }

        try {
            foreach ($events as $event) {
                $processor->handle($event);
            }
        } catch (\Throwable $e) {
            Log::error('Steadfast webhook processing failed.', [
                'message' => $e->getMessage(),
                'invoice' => $events[0]['invoice'] ?? null,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function requestPayload(Request $request): array
    {
        $payload = $request->all();
        if (is_array($payload) && $payload !== []) {
            return $payload;
        }

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('steadfast.webhook.token');

        if (! $expected) {
            Log::warning('Steadfast webhook received but STEADFAST_WEBHOOK_TOKEN is not configured.');

            return false;
        }

        $authorization = trim((string) $request->header('Authorization', ''));

        if (preg_match('/^Bearer\s+(\S.*)$/i', $authorization, $matches) === 1) {
            $provided = trim($matches[1]);
            if ($provided !== '' && hash_equals($expected, $provided)) {
                return true;
            }
        }

        if ($authorization !== '' && ! str_contains($authorization, ' ') && hash_equals($expected, $authorization)) {
            return true;
        }

        $alternate = (string) $request->header('X-Steadfast-Token', '');

        return $alternate !== '' && hash_equals($expected, $alternate);
    }
}
