<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ads lab (storefront test page)
    |--------------------------------------------------------------------------
    |
    | Dedicated /ads-lab page for previewing ad formats before sitewide rollout.
    | Not linked from nav or footer; set ADS_LAB_ENABLED=false to disable the route.
    |
    */

    'lab_enabled' => (bool) env('ADS_LAB_ENABLED', true),

    'network' => env('ADS_NETWORK', 'adsterra'),

    /*
    |--------------------------------------------------------------------------
    | Adsterra slot keys
    |--------------------------------------------------------------------------
    |
    | Paste each unit key from the Adsterra dashboard (High Performance Tag).
    | Leave blank to show a labelled placeholder on the lab page.
    |
    */

    'adsterra' => [
        'banner_728' => [
            'label' => '728×90 Leaderboard',
            'description' => 'Wide desktop banner — common above content.',
            'key' => env('ADSTERRA_SLOT_BANNER_728'),
            'width' => 728,
            'height' => 90,
            'format' => 'iframe',
        ],
        'banner_300' => [
            'label' => '300×250 Medium rectangle',
            'description' => 'Sidebar / in-content rectangle — works on mobile and desktop.',
            'key' => env('ADSTERRA_SLOT_BANNER_300'),
            'width' => 300,
            'height' => 250,
            'format' => 'iframe',
        ],
        'banner_320' => [
            'label' => '320×50 Mobile banner',
            'description' => 'Compact mobile strip.',
            'key' => env('ADSTERRA_SLOT_BANNER_320'),
            'width' => 320,
            'height' => 50,
            'format' => 'iframe',
        ],
        'native' => [
            'label' => 'Native banner',
            'description' => 'Blends with content styling.',
            'key' => env('ADSTERRA_SLOT_NATIVE'),
            'width' => 300,
            'height' => 250,
            'format' => 'iframe',
        ],
        'social_bar' => [
            'label' => 'Social bar',
            'description' => 'Sticky bottom or side bar unit.',
            'key' => env('ADSTERRA_SLOT_SOCIAL_BAR'),
            'width' => 728,
            'height' => 90,
            'format' => 'iframe',
        ],
        'video_slider' => [
            'label' => 'Video / in-page push',
            'description' => 'Video-style slider or in-page push unit.',
            'key' => env('ADSTERRA_SLOT_VIDEO'),
            'width' => 400,
            'height' => 300,
            'format' => 'iframe',
        ],
    ],

    /*
    | Full script snippets for formats that are not key-based (e.g. popunder).
    | Paste the exact code block from Adsterra when ready.
    */

    'adsterra_scripts' => [
        'popunder' => [
            'label' => 'Pop-under',
            'description' => 'Opens in a new tab on trigger — test carefully; browsers may block.',
            'body' => env('ADSTERRA_SCRIPT_POPUNDER'),
        ],
    ],

];
