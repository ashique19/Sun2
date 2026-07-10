<?php

return [
    'api_key' => env('GEMINI_API_KEY', env('GOOGLE_GEMINI_API_KEY', env('GOOGLE_API_KEY'))),

    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 20),
];
