<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Facebook / Messenger
    |--------------------------------------------------------------------------
    |
    | Callback URL for Meta App Dashboard → Messenger → Webhooks:
    |   {APP_URL}/api/webhooks/messenger
    |
    | Subscribe webhook fields at least: messages, standby
    | (standby is required when Page Inbox / another app is the primary receiver).
    |
    | Verify token must match FACEBOOK_MESSENGER_VERIFY_TOKEN exactly.
    |
    */

    // Graph API version used for Messenger / Page API calls (e.g. subscribed_apps, Send API).
    'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),

    'messenger' => [
        'enabled' => filter_var(env('FACEBOOK_MESSENGER_WEBHOOK_ENABLED', true), FILTER_VALIDATE_BOOL),

        'verify_token' => env('FACEBOOK_MESSENGER_VERIFY_TOKEN'),

        // App Secret from Meta App Dashboard → Settings → Basic (for X-Hub-Signature-256).
        'app_secret' => env('FACEBOOK_APP_SECRET'),

        // Page access token (send replies / publish posts).
        // Runtime override may also be stored in `settings.facebook.page_access_token`
        // via Admin Inbox / Social Posts token gate when the env token expires.
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),

        'page_id' => env('FACEBOOK_PAGE_ID'),

        /*
        | Scheduled Graph Conversations API catch-up (php artisan schedule:run).
        | Webhooks remain the realtime path; this backfills missed/history threads.
        */
        'auto_sync_enabled' => filter_var(env('FACEBOOK_MESSENGER_AUTO_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),

        /*
        | Secret token for HTTP sync (hosting panel cron / curl):
        |   GET /internal/messenger/sync-conversations?token=...
        */
        'sync_token' => env('FACEBOOK_MESSENGER_SYNC_TOKEN'),
    ],
];
