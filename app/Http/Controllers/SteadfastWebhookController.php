<?php

namespace App\Http\Controllers;

use App\Services\Couriers\SteadfastWebhookPayload;
use App\Services\Couriers\SteadfastWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SteadfastWebhookController extends Controller
{
    /**
     * Steadfast retries the same Idempotency-Key after 30s and 2 minutes.
     * Keep the key long enough that a delayed third hop is still skipped.
     */
    private const IDEMPOTENCY_TTL_DAYS = 7;

    public function __invoke(Request $request, SteadfastWebhookProcessor $processor): JsonResponse
    {
        if (! config('steadfast.webhook.enabled', true)) {
            return response()->json(['status' => 'disabled'], 503);
        }

        if (! $this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->hasValidSignature($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $idempotencyCacheKey = $this->idempotencyCacheKey($request);
        if ($idempotencyCacheKey !== null && Cache::has($idempotencyCacheKey)) {
            return response()->json(['status' => 'success']);
        }

        $events = SteadfastWebhookPayload::eventsFrom($this->requestPayload($request));

        if ($events === []) {
            Log::warning('Steadfast webhook: empty or unusable payload.');

            // Steadfast never retries 4xx. Ack empty bodies so a parser miss is not fatal.
            $this->rememberIdempotencyKey($idempotencyCacheKey);

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

        $this->rememberIdempotencyKey($idempotencyCacheKey);

        return response()->json(['status' => 'success']);
    }

    /**
     * Decode the same raw bytes used for X-Signature. Steadfast signs php://input,
     * not a re-serialized JSON object.
     *
     * @return array<string, mixed>|list<mixed>
     */
    private function requestPayload(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        $payload = $request->all();

        return is_array($payload) ? $payload : [];
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

    /**
     * Official docs: HMAC-SHA256 of the raw body, hex, keyed with the auth token.
     *
     * 4xx is never retried, so a missing X-Signature still authenticates via Bearer.
     * A present but wrong signature is 401, matching their PHP sample.
     */
    private function hasValidSignature(Request $request): bool
    {
        $given = trim((string) $request->header('X-Signature', ''));
        if ($given === '') {
            return true;
        }

        if (str_starts_with(strtolower($given), 'sha256=')) {
            $given = substr($given, 7);
        }

        $expected = hash_hmac('sha256', $request->getContent(), (string) config('steadfast.webhook.token'));

        if (hash_equals($expected, strtolower($given))) {
            return true;
        }

        Log::warning('Steadfast webhook: X-Signature mismatch.');

        return false;
    }

    private function idempotencyCacheKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }

        return 'steadfast.webhook.idempotency.'.hash('sha256', $key);
    }

    private function rememberIdempotencyKey(?string $cacheKey): void
    {
        if ($cacheKey === null) {
            return;
        }

        Cache::put($cacheKey, 1, now()->addDays(self::IDEMPOTENCY_TTL_DAYS));
    }
}
