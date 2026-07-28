<?php

namespace App\Http\Controllers;

use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\WhatsAppInboundService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta WhatsApp Cloud API webhook verification (GET) + event receiver (POST).
     */
    public function __invoke(
        Request $request,
        WhatsAppInboundService $inbound,
        ChannelInboxDiagnostics $diagnostics,
    ): Response|SymfonyResponse {
        if (! config('whatsapp.enabled', true)) {
            $diagnostics->recordWhatsAppRejected('WhatsApp webhook disabled in config.');

            return response('WhatsApp webhook disabled', 503);
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

        $expected = (string) config('whatsapp.verify_token', '');

        if ($expected === '') {
            Log::warning('WhatsApp webhook verify attempted but WHATSAPP_VERIFY_TOKEN is not set.');
            $diagnostics->recordWhatsAppRejected('Verify token not configured.');

            return response('Verify token not configured', 503);
        }

        if ($mode === 'subscribe' && hash_equals($expected, $token) && $challenge !== '') {
            $diagnostics->recordWhatsAppVerified();

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed.', [
            'mode' => $mode,
            'token_match' => $expected !== '' && hash_equals($expected, $token),
        ]);
        $diagnostics->recordWhatsAppRejected('Webhook verification failed (mode/token mismatch).');

        return response('Forbidden', 403);
    }

    private function receive(
        Request $request,
        WhatsAppInboundService $inbound,
        ChannelInboxDiagnostics $diagnostics,
    ): Response {
        if (! $this->signatureIsValid($request)) {
            $diagnostics->recordWhatsAppRejected('Invalid signature (check WHATSAPP_APP_SECRET / FACEBOOK_APP_SECRET).');

            return response('Invalid signature', 401);
        }

        $payload = $request->all();
        $entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;

        Log::info('WhatsApp webhook event received.', [
            'object' => $payload['object'] ?? null,
            'entry_count' => $entryCount,
        ]);

        $diagnostics->recordWhatsAppReceived($entryCount);

        try {
            $inbound->handleWebhookPayload($payload);
        } catch (Throwable $e) {
            Log::error('WhatsApp webhook processing error.', [
                'message' => $e->getMessage(),
            ]);
            $diagnostics->recordWhatsAppError($e->getMessage());
        }

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    private function signatureIsValid(Request $request): bool
    {
        $appSecret = (string) config('whatsapp.app_secret', '');

        if ($appSecret === '') {
            if (app()->environment('production')) {
                Log::warning('WhatsApp webhook POST rejected: WHATSAPP_APP_SECRET missing in production.');

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
