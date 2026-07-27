<?php

return [
    'api_key' => env('GEMINI_API_KEY', env('GOOGLE_GEMINI_API_KEY', env('GOOGLE_API_KEY'))),

    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    // Image-capable model for product photo generation / edit (Nano Banana family).
    'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),

    'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 20),

    'image_timeout' => (int) env('GEMINI_IMAGE_TIMEOUT', 90),
];
