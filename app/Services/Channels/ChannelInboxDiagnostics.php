<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\Setting;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Support\Carbon;

class ChannelInboxDiagnostics
{
    public const MESSENGER_HEALTH_KEY = 'channel_webhook.messenger.health';

    public function __construct(
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * @param  array{channel?: string, unread?: string, window?: string, linked?: string}  $filters
     * @return array{
     *     total_conversations: int,
     *     messenger_conversations: int,
     *     filtered_out: bool,
     *     filters_active: bool,
     *     webhook_url: string,
     *     checks: list<array{ok: bool, label: string, detail: string}>,
     *     summary: string,
     *     severity: string
     * }
     */
    public function forInbox(array $filters = []): array
    {
        $total = ChannelConversation::query()->count();
        $messengerCount = ChannelConversation::query()
            ->where('channel', ChannelConversation::CHANNEL_MESSENGER)
            ->count();

        $filtersActive = ($filters['channel'] ?? '') !== ''
            || ($filters['unread'] ?? '') !== ''
            || ($filters['window'] ?? '') !== ''
            || ($filters['linked'] ?? '') !== '';

        $tokenStatus = $this->tokens->status();
        $messengerHealth = $this->health(self::MESSENGER_HEALTH_KEY);

        $webhookEnabled = (bool) config('facebook.messenger.enabled', true);
        $verifyTokenSet = trim((string) config('facebook.messenger.verify_token', '')) !== '';
        $appSecretSet = trim((string) config('facebook.messenger.app_secret', '')) !== '';
        $pageIdSet = $this->tokens->pageId() !== '';

        $checks = [];

        $checks[] = [
            'ok' => $webhookEnabled,
            'label' => 'Messenger webhook enabled',
            'detail' => $webhookEnabled
                ? 'FACEBOOK_MESSENGER_WEBHOOK_ENABLED is on.'
                : 'Webhook is disabled in config — Meta events are rejected.',
        ];

        $checks[] = [
            'ok' => $verifyTokenSet,
            'label' => 'Verify token configured',
            'detail' => $verifyTokenSet
                ? 'FACEBOOK_MESSENGER_VERIFY_TOKEN is set.'
                : 'Missing FACEBOOK_MESSENGER_VERIFY_TOKEN — Meta cannot verify the webhook.',
        ];

        $checks[] = [
            'ok' => $appSecretSet,
            'label' => 'App secret configured',
            'detail' => $appSecretSet
                ? 'FACEBOOK_APP_SECRET is set (needed to accept webhook POSTs in production).'
                : 'Missing FACEBOOK_APP_SECRET — production webhook POSTs are rejected with Invalid signature.',
        ];

        $checks[] = [
            'ok' => $pageIdSet,
            'label' => 'Facebook Page ID configured',
            'detail' => $pageIdSet
                ? 'FACEBOOK_PAGE_ID is set.'
                : 'Missing FACEBOOK_PAGE_ID.',
        ];

        $checks[] = [
            'ok' => (bool) $tokenStatus['valid'],
            'label' => 'Page access token',
            'detail' => $tokenStatus['message'],
        ];

        $checks[] = [
            'ok' => ! empty($messengerHealth['last_verified_at']),
            'label' => 'Webhook verification seen',
            'detail' => ! empty($messengerHealth['last_verified_at'])
                ? 'Last successful Meta verify: '.$this->formatWhen($messengerHealth['last_verified_at'])
                : 'No successful webhook verification recorded yet. Confirm Meta callback URL and verify token.',
        ];

        $lastReceived = $messengerHealth['last_received_at'] ?? null;
        $hasMessaging = ! empty($messengerHealth['last_has_messaging']);
        $hasStandby = ! empty($messengerHealth['last_has_standby']);
        $messagingCount = (int) ($messengerHealth['last_messaging_count'] ?? 0);
        $standbyCount = (int) ($messengerHealth['last_standby_count'] ?? 0);

        $checks[] = [
            'ok' => ! empty($lastReceived),
            'label' => 'Messenger events received',
            'detail' => ! empty($lastReceived)
                ? 'Last webhook POST: '.$this->formatWhen($lastReceived)
                    .(! empty($messengerHealth['last_entry_count']) ? ' (entries: '.$messengerHealth['last_entry_count'].')' : '')
                    .($messagingCount > 0 || $standbyCount > 0
                        ? ' · messaging: '.$messagingCount.', standby: '.$standbyCount
                        : '')
                : 'No Messenger webhook POSTs recorded. Inbox only fills when Meta sends message events to this app.',
        ];

        $checks[] = [
            'ok' => ! (! empty($lastReceived) && ! $hasMessaging && ! $hasStandby),
            'label' => 'Message content fields',
            'detail' => (! empty($lastReceived) && ! $hasMessaging && ! $hasStandby)
                ? 'Last webhook had no messaging/standby events (delivery/read noise only, or wrong subscription fields). Subscribe to messages and standby in Meta Developer → Webhooks.'
                : ($hasStandby
                    ? 'Standby events are present (Page Inbox is likely primary). This app now ingests standby as well as messaging.'
                    : 'Webhook includes messaging events (or none received yet).'),
        ];

        $checks[] = [
            'ok' => $messengerCount > 1,
            'label' => 'Meta app Live mode / testers (required for other customers)',
            'detail' => 'Development mode is NOT enough even if you own the Page. Meta only delivers Messenger webhooks/API threads for people with an app role (Admin/Developer/Tester). '
                .'Your personal Facebook ID works because you are an app Admin. Other customers will NOT appear until you either (1) add each tester Facebook account under App Roles → Roles, or (2) switch the app to Live and get pages_messaging Advanced Access. '
                .'Use “Sync from Facebook” to pull whatever Graph can currently see.',
        ];

        if (! empty($messengerHealth['last_rejection_reason'])) {
            $checks[] = [
                'ok' => false,
                'label' => 'Last webhook rejection',
                'detail' => ($messengerHealth['last_rejected_at'] ? $this->formatWhen($messengerHealth['last_rejected_at']).': ' : '')
                    .$messengerHealth['last_rejection_reason'],
            ];
        }

        if (! empty($messengerHealth['last_error'])) {
            $checks[] = [
                'ok' => false,
                'label' => 'Last processing error',
                'detail' => ($messengerHealth['last_error_at'] ? $this->formatWhen($messengerHealth['last_error_at']).': ' : '')
                    .$messengerHealth['last_error'],
            ];
        }

        $checks[] = [
            'ok' => $messengerCount > 0,
            'label' => 'Messenger conversations in database',
            'detail' => $messengerCount > 0
                ? $messengerCount.' conversation(s) stored.'
                : 'channel_conversations has 0 Messenger rows. Nothing to list until a customer message is ingested.',
        ];

        $failed = collect($checks)->contains(fn (array $check) => $check['ok'] === false);
        $filteredOut = $filtersActive && $total > 0;

        if ($filteredOut) {
            $summary = 'Conversations exist in the database, but current filters hide them. Clear filters to see them.';
            $severity = 'warning';
        } elseif ($total === 0 && $failed) {
            $summary = 'Inbox is empty because no conversations have been ingested yet, and one or more Messenger setup checks are failing.';
            $severity = 'error';
        } elseif ($total === 0) {
            $summary = 'Inbox is empty. Config looks okay so far — waiting for Meta to deliver a Messenger message webhook.';
            $severity = 'info';
        } elseif ($messengerCount <= 1) {
            $summary = 'Only your app-role chats can appear while the Meta app is in Development mode. Being Page owner/admin is not enough for other Facebook users — add them as App Testers, or switch the app to Live. Use “Sync Messenger” to import tester threads Graph can see.';
            $severity = 'warning';
        } else {
            $summary = 'Conversations are loading from the local database (webhook ingest).';
            $severity = $failed ? 'warning' : 'ok';
        }

        return [
            'total_conversations' => $total,
            'messenger_conversations' => $messengerCount,
            'filtered_out' => $filteredOut,
            'filters_active' => $filtersActive,
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/webhooks/messenger',
            'checks' => $checks,
            'summary' => $summary,
            'severity' => $severity,
        ];
    }

    public function recordMessengerVerified(): void
    {
        $this->patchHealth(self::MESSENGER_HEALTH_KEY, [
            'last_verified_at' => now()->toIso8601String(),
        ]);
    }

    public function recordMessengerReceived(
        int $entryCount,
        bool $hasMessaging,
        bool $hasStandby = false,
        int $messagingCount = 0,
        int $standbyCount = 0,
    ): void {
        $this->patchHealth(self::MESSENGER_HEALTH_KEY, [
            'last_received_at' => now()->toIso8601String(),
            'last_entry_count' => $entryCount,
            'last_has_messaging' => $hasMessaging,
            'last_has_standby' => $hasStandby,
            'last_messaging_count' => $messagingCount,
            'last_standby_count' => $standbyCount,
            'last_rejection_reason' => null,
            'last_rejected_at' => null,
        ]);
    }

    public function recordMessengerRejected(string $reason): void
    {
        $this->patchHealth(self::MESSENGER_HEALTH_KEY, [
            'last_rejected_at' => now()->toIso8601String(),
            'last_rejection_reason' => $reason,
        ]);
    }

    public function recordMessengerError(string $message): void
    {
        $this->patchHealth(self::MESSENGER_HEALTH_KEY, [
            'last_error_at' => now()->toIso8601String(),
            'last_error' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function health(string $key): array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchHealth(string $key, array $patch): void
    {
        $current = $this->health($key);
        $merged = array_merge($current, $patch);
        Setting::putValue($key, json_encode($merged), 'channels');
    }

    private function formatWhen(string $iso): string
    {
        try {
            return Carbon::parse($iso)->timezone('Asia/Dhaka')->format('d M Y, h:i A').' (Asia/Dhaka)';
        } catch (\Throwable) {
            return $iso;
        }
    }
}
