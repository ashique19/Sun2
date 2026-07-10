<?php

return [
    'enabled' => (bool) env('REDX_ENABLED', false),

    'base_url' => rtrim(env('REDX_BASE_URL', 'https://openapi.redx.com.bd/v1.0.0-beta'), '/'),

    'api_token' => env('REDX_API_TOKEN'),

    'token_header' => env('REDX_TOKEN_HEADER', 'API-ACCESS-TOKEN'),

    'pickup_store_id' => (int) env('REDX_PICKUP_STORE_ID', 0),

    'default_weight' => (float) env('REDX_DEFAULT_WEIGHT', 1),

    'timeout' => (int) env('REDX_TIMEOUT', 30),

    'endpoints' => [
        'create_parcel' => '/parcel',
        'areas' => '/areas',
    ],

    'webhook' => [
        'enabled' => (bool) env('REDX_WEBHOOK_ENABLED', true),
        'token' => env('REDX_WEBHOOK_TOKEN'),
        'secret_header' => env('REDX_WEBHOOK_SECRET_HEADER', 'X-Redx-Webhook-Secret'),
        'path' => trim(env('REDX_WEBHOOK_PATH', 'api/webhooks/redx'), '/'),
    ],
];
