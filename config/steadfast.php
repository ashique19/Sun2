<?php

return [
    'base_url' => rtrim(env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'), '/'),

    'api_key' => env('STEADFAST_API_KEY', env('STEADFAST_KEY', env('PACKZY_API_KEY'))),

    'secret_key' => env('STEADFAST_SECRET_KEY', env('STEADFAST_SECRET', env('STEADFAST_API_SECRET', env('PACKZY_SECRET_KEY')))),

    'timeout' => (int) env('STEADFAST_TIMEOUT', 30),

    /*
    | Merchant-panel fraud check (fallback when Packzy /fraud_check fails or is unavailable).
    | Uses the same email/password as https://steadfast.com.bd login.
    */
    'fraud' => [
        'panel_url' => rtrim(env('STEADFAST_FRAUD_PANEL_URL', 'https://steadfast.com.bd'), '/'),

        'email' => env('STEADFAST_EMAIL', env('STEADFAST_FRAUD_EMAIL', env('STEADFAST_FRAUD_CHECKER_EMAIL', env('STEADFAST_USER')))),

        'password' => env('STEADFAST_PASSWORD', env('STEADFAST_FRAUD_PASSWORD', env('STEADFAST_FRAUD_CHECKER_PASSWORD'))),
    ],

    'webhook' => [
        'enabled' => (bool) env('STEADFAST_WEBHOOK_ENABLED', true),

        'token' => env('STEADFAST_WEBHOOK_TOKEN'),

        'path' => trim(env('STEADFAST_WEBHOOK_PATH', 'api/steadfast/webhook'), '/'),
    ],
];
