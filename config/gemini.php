<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini API keys
    |--------------------------------------------------------------------------
    |
    | Primary key plus optional extras. Requests try keys in order when one
    | is rate-limited, unauthorized, or otherwise unavailable.
    |
    | GEMINI_API_KEYS=key-a,key-b,key-c
    |
    */
    'api_key' => env('GEMINI_API_KEY', env('GOOGLE_GEMINI_API_KEY', env('GOOGLE_API_KEY'))),

    'api_keys' => array_values(array_filter(array_map(
        static fn (string $key): string => trim($key),
        explode(',', (string) env('GEMINI_API_KEYS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Text / JSON models (tried in order)
    |--------------------------------------------------------------------------
    |
    | Used for inbox/order parse and paste parse (generateJson*).
    | Prefer free-capable Flash / Flash-Lite models first; Pro last if added via env.
    |
    */
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    'models' => array_values(array_filter(array_map(
        static fn (string $model): string => trim($model),
        explode(',', (string) env(
            'GEMINI_MODELS',
            'gemini-2.5-flash,gemini-2.5-flash-lite,gemini-3.1-flash-lite,gemini-3-flash-preview,gemini-2.0-flash',
        )),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Image models (tried in order)
    |--------------------------------------------------------------------------
    |
    | Used for product AI photo generate/edit (generateImage).
    | Image models are generally paid on the Gemini API; cheaper Flash first, Pro last.
    |
    */
    'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),

    'image_models' => array_values(array_filter(array_map(
        static fn (string $model): string => trim($model),
        explode(',', (string) env(
            'GEMINI_IMAGE_MODELS',
            'gemini-2.5-flash-image,gemini-3.1-flash-image,gemini-3.1-flash-image-preview,gemini-3-pro-image,gemini-3-pro-image-preview',
        )),
    ))),

    'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 20),

    'image_timeout' => (int) env('GEMINI_IMAGE_TIMEOUT', 90),
];
