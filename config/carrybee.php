<?php

return [
    'enabled' => (bool) env('CARRYBEE_ENABLED', false),

    'environment' => env('CARRYBEE_ENVIRONMENT', 'sandbox'),

    'base_url' => env('CARRYBEE_BASE_URL', match (env('CARRYBEE_ENVIRONMENT', 'sandbox')) {
        'production' => 'https://developers.carrybee.com',
        default => 'https://stage-sandbox.carrybee.com',
    }),

    'client_id' => env('CARRYBEE_CLIENT_ID'),
    'client_secret' => env('CARRYBEE_CLIENT_SECRET'),
    'client_context' => env('CARRYBEE_CLIENT_CONTEXT'),

    'store_id' => env('CARRYBEE_STORE_ID'),

    'delivery_type' => (int) env('CARRYBEE_DELIVERY_TYPE', 1),
    'product_type' => (int) env('CARRYBEE_PRODUCT_TYPE', 1),
    'default_weight_grams' => (int) env('CARRYBEE_DEFAULT_WEIGHT_GRAMS', 500),

    'timeout' => (int) env('CARRYBEE_TIMEOUT', 30),

    'webhook' => [
        'enabled' => (bool) env('CARRYBEE_WEBHOOK_ENABLED', true),
        'secret' => env('CARRYBEE_WEBHOOK_SECRET'),
        'path' => trim(env('CARRYBEE_WEBHOOK_PATH', 'api/webhooks/carrybee'), '/'),
    ],
];
