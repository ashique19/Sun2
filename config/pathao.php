<?php

return [
    'enabled' => (bool) env('PATHAO_ENABLED', false),

    'environment' => env('PATHAO_ENVIRONMENT', 'sandbox'),

    'base_url' => env('PATHAO_BASE_URL', match (env('PATHAO_ENVIRONMENT', 'sandbox')) {
        'production' => 'https://courier-api.pathao.com',
        default => 'https://courier-api-sandbox.pathao.com',
    }),

    'client_id' => env('PATHAO_CLIENT_ID'),
    'client_secret' => env('PATHAO_CLIENT_SECRET'),
    'username' => env('PATHAO_USERNAME'),
    'password' => env('PATHAO_PASSWORD'),

    /*
    | Merchant-panel phone success lookup (login + /api/v1/user/success).
    | Independent of PATHAO_ENABLED / store dispatch credentials beyond username/password.
    */
    'scrap' => filter_var(env('SCRAP_PATHAO', false), FILTER_VALIDATE_BOOL),
    'merchant_base_url' => env('PATHAO_MERCHANT_BASE_URL', 'https://merchant.pathao.com'),
    'scrap_timeout' => (int) env('PATHAO_SCRAP_TIMEOUT', 20),

    'store_id' => (int) env('PATHAO_STORE_ID', 0),

    'delivery_type' => (int) env('PATHAO_DELIVERY_TYPE', 48),
    'item_type' => (int) env('PATHAO_ITEM_TYPE', 2),
    'default_weight' => (float) env('PATHAO_DEFAULT_WEIGHT', 0.5),

    'timeout' => (int) env('PATHAO_TIMEOUT', 30),

    'webhook' => [
        'enabled' => (bool) env('PATHAO_WEBHOOK_ENABLED', true),
        'secret' => env('PATHAO_WEBHOOK_SECRET'),
        'integration_secret' => env('PATHAO_WEBHOOK_INTEGRATION_SECRET'),
        'path' => trim(env('PATHAO_WEBHOOK_PATH', 'api/webhooks/pathao'), '/'),
    ],
];
