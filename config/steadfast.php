<?php

return [
    'base_url' => rtrim(env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'), '/'),

    'api_key' => env('STEADFAST_API_KEY', env('STEADFAST_KEY', env('PACKZY_API_KEY'))),

    'secret_key' => env('STEADFAST_SECRET_KEY', env('STEADFAST_SECRET', env('STEADFAST_API_SECRET', env('PACKZY_SECRET_KEY')))),

    'timeout' => (int) env('STEADFAST_TIMEOUT', 30),

    'webhook' => [
        'enabled' => (bool) env('STEADFAST_WEBHOOK_ENABLED', true),

        'token' => env('STEADFAST_WEBHOOK_TOKEN'),

        'path' => trim(env('STEADFAST_WEBHOOK_PATH', 'api/steadfast/webhook'), '/'),
    ],
];
