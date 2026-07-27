<?php

namespace App\Http\Controllers;

use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\MessengerInboundService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class FacebookMessengerWebhookController extends Controller
{
    /**
     * Meta webhook verification (GET) + event receiver (POST).
     */
    public function __invoke(
        Request $request,
        MessengerInboundService $inbound,
        ChannelInboxDiagnostics $diagnostics,
    ): Response|SymfonyResponse {
        if (! config('facebook.messenger.enabled', true)) {
            $diagnostics->recordMessengerRejected('Messenger webhook disabled in config.');

            return response('Messenger webhook disabled', 503);
        }

        if ($request->isMethod('get')) {
            return $this->verify($request, $diagnostics);
        }

        return $this->receive($request, $inbound, $diagnostics);
    }

    private function verify(Request $request, ChannelInboxDiagnostics $diagnostics): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('facebook.messenger.verify_token', '');

        if ($expected === '') {
            Log::warning('Facebook Messenger webhook verify attempted but FACEBOOK_MESSENGER_VERIFY_TOKEN is not set.');
            $diagnostics->recordMessengerRejected('Verify token not configured.');

            return response('Verify token not configured', 503);
        }

        if ($mode === 'subscribe' && hash_equals($expected, $token) && $challenge !== '') {
            $diagnostics->recordMessengerVerified();

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Facebook Messenger webhook verification failed.', [
            'mode' => $mode,
            'token_match' => $expected !== '' && hash_equals($expected, $token),
        ]);
        $diagnostics->recordMessengerRejected('Webhook verification failed (mode/token mismatch).');

        return response('Forbidden', 403);
    }

    private function receive(
        Request $request,
        MessengerInboundService $inbound,
        ChannelInboxDiagnostics $diagnostics,
    ): Response {
        if (! $this->signatureIsValid($request)) {
            Log::warning('Facebook Messenger webhook signature validation failed', [
                'has_app_secret' => config('facebook.messenger.app_secret') !== '',
                'environment' => app()->environment(),
                'header_present' => $request->hasHeader('X-Hub-Signature-256'),
            ]);
            $diagnostics->recordMessengerRejected('Invalid signature (check FACEBOOK_APP_SECRET).');

            return response('Invalid signature', 401);
        }

        $payload = $request->all();
        $entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;
        $messagingCount = 0;
        $standbyCount = 0;

        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $messagingCount += is_array($entry['messaging'] ?? null) ? count($entry['messaging']) : 0;
            $standbyCount += is_array($entry['standby'] ?? null) ? count($entry['standby']) : 0;
        }

        Log::info('Facebook Messenger webhook event received.', [
            'object' => $payload['object'] ?? null,
            'entry_count' => $entryCount,
            'messaging_count' => $messagingCount,
            'standby_count' => $standbyCount,
        ]);

        $diagnostics->recordMessengerReceived($entryCount, $messagingCount > 0, $standbyCount > 0, $messagingCount, $standbyCount);

        try {
            $inbound->handleWebhookPayload($payload);
        } catch (Throwable $e) {
            // Always ACK to Meta so retries do not storm; log for ops.
            Log::error('Messenger webhook processing error.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $diagnostics->recordMessengerError($e->getMessage());
        }

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    private function signatureIsValid(Request $request): bool
    {
        $appSecret = (string) config('facebook.messenger.app_secret', '');

        // Allow local/dev receive without signature when app secret is not configured yet.
        if ($appSecret === '') {
            if (app()->environment('production')) {
                Log::warning('Facebook Messenger webhook POST rejected: FACEBOOK_APP_SECRET missing in production.');

                return false;
            }

            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $header);
    }
}
