<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | Callback URL for Meta App Dashboard → WhatsApp → Configuration:
    |   {APP_URL}/api/webhooks/whatsapp
    |
    | Verify token must match WHATSAPP_VERIFY_TOKEN exactly.
    |
    */

    'enabled' => (bool) env('WHATSAPP_WEBHOOK_ENABLED', true),

    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    // App Secret (X-Hub-Signature-256). Often same Meta app as Messenger.
    'app_secret' => env('WHATSAPP_APP_SECRET', env('FACEBOOK_APP_SECRET')),

    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', env('FACEBOOK_GRAPH_VERSION', 'v25.0')),
];
