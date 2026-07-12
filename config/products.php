<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Images processed per Livewire poll tick / HTTP chunk
    |--------------------------------------------------------------------------
    |
    | Keep this modest when hashing from CDN (network + GD resize per image).
    |
    */
    'image_hash_chunk_size' => (int) env('PRODUCT_IMAGE_HASH_CHUNK_SIZE', 25),

    /*
    |--------------------------------------------------------------------------
    | Secret token for HTTP rebuild (hosting panel cron / curl)
    |--------------------------------------------------------------------------
    |
    | GET /internal/product-image-hashes/rebuild?token=...
    | Optional: &force=1 to re-hash images that already have a hash
    |
    */
    'image_hash_rebuild_token' => env('PRODUCT_IMAGE_HASH_REBUILD_TOKEN'),

];
