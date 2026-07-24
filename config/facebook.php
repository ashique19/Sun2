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
    | Verify token must match FACEBOOK_MESSENGER_VERIFY_TOKEN exactly.
    |
    */

    // Graph API version used for Messenger / Page API calls (e.g. subscribed_apps, Send API).
    'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),

    'messenger' => [
        'enabled' => (bool) env('FACEBOOK_MESSENGER_WEBHOOK_ENABLED', true),

        'verify_token' => env('FACEBOOK_MESSENGER_VERIFY_TOKEN'),

        // App Secret from Meta App Dashboard → Settings → Basic (for X-Hub-Signature-256).
        'app_secret' => env('FACEBOOK_APP_SECRET'),

        // Page access token (send replies later). Not required for webhook verify.
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),

        'page_id' => env('FACEBOOK_PAGE_ID'),
    ],
];
