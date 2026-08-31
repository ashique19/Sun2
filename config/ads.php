<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ads lab (storefront test page)
    |--------------------------------------------------------------------------
    |
    | Dedicated /ads-lab page for previewing ad formats before sitewide rollout.
    | Unit codes live in the `settings` table (key: ads.lab.units, group: ads).
    | default_banners / default_scripts are seed/fallback only — prefer DB.
    |
    */

    'lab_enabled' => (bool) env('ADS_LAB_ENABLED', true),

    'network' => env('ADS_NETWORK', 'adsterra'),

    'invoke_host' => env('ADSTERRA_INVOKE_HOST', 'www.highrevenueformat.com'),

    /*
    | Steady storefront placements (read unit codes from settings).
    | product_after_description → 728×90 after product description.
    | popunder → first click smartlink / network script (excludes checkout & auth).
    */
    'placements' => [
        'product_after_description' => (bool) env('ADS_PRODUCT_AFTER_DESCRIPTION', true),
        'popunder' => (bool) env('ADS_POPUNDER_ENABLED', true),
    ],

    /*
    | Route name patterns where popunder must not run (checkout, account, lab, etc.).
    */
    'popunder_excluded_routes' => [
        'cart',
        'checkout',
        'checkout.*',
        'login',
        'register',
        'password.*',
        'account',
        'account.*',
        'logout',
        'admin.*',
        'reseller.*',
        'ads.lab',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default units (seeded into settings; used if DB row is missing)
    |--------------------------------------------------------------------------
    */

    'default_banners' => [
        'banner_728' => [
            'label' => '728×90 Leaderboard',
            'description' => 'Wide desktop banner — common above content.',
            'type' => 'atoptions',
            'key' => '6749cdd1ebf2dbcda3384c9f4c4f8cfb',
            'width' => 728,
            'height' => 90,
            'format' => 'iframe',
        ],
        'banner_468' => [
            'label' => '468×60 Banner',
            'description' => 'Classic mid-width banner.',
            'type' => 'atoptions',
            'key' => 'fd48224ebf3f09fd56ece982c1585ddf',
            'width' => 468,
            'height' => 60,
            'format' => 'iframe',
        ],
        'banner_300' => [
            'label' => '300×250 Medium rectangle',
            'description' => 'Sidebar / in-content rectangle — works on mobile and desktop.',
            'type' => 'atoptions',
            'key' => 'a356eb5486bfece119efb08195fb4a25',
            'width' => 300,
            'height' => 250,
            'format' => 'iframe',
        ],
        'banner_320' => [
            'label' => '320×50 Mobile banner',
            'description' => 'Compact mobile strip.',
            'type' => 'atoptions',
            'key' => '2b562aa780f28739eee1965844207030',
            'width' => 320,
            'height' => 50,
            'format' => 'iframe',
        ],
        'skyscraper_160_600' => [
            'label' => '160×600 Wide skyscraper',
            'description' => 'Tall sidebar unit — best on desktop layouts.',
            'type' => 'atoptions',
            'key' => 'e142d88c96eac091e3874ed87e55e37c',
            'width' => 160,
            'height' => 600,
            'format' => 'iframe',
        ],
        'skyscraper_160_300' => [
            'label' => '160×300 Half skyscraper',
            'description' => 'Shorter sidebar skyscraper.',
            'type' => 'atoptions',
            'key' => '4b1ba3480be15d58894a81dd7321ed51',
            'width' => 160,
            'height' => 300,
            'format' => 'iframe',
        ],
        'native' => [
            'label' => 'Native banner',
            'description' => 'Container-based native unit (profitableratecpmnetwork).',
            'type' => 'native_container',
            'key' => '0150ba9fc718f1f7f103b72a3757ae25',
            'script_src' => 'https://pl31110128.profitableratecpmnetwork.com/0150ba9fc718f1f7f103b72a3757ae25/invoke.js',
            'width' => 300,
            'height' => 250,
        ],
    ],

    'default_scripts' => [
        'social_bar' => [
            'label' => 'Social bar',
            'description' => 'Sticky social-bar style script — observe placement and intensity.',
            'type' => 'script_src',
            'src' => 'https://pl31110125.profitableratecpmnetwork.com/2e/28/d6/2e28d6b523d7ac452ad571b5139de0eb.js',
        ],
        'in_page_push' => [
            'label' => 'In-page push / sticky',
            'description' => 'Second script unit from Adsterra — confirm label in dashboard if unsure.',
            'type' => 'script_src',
            'src' => 'https://pl31110126.profitableratecpmnetwork.com/4f/ea/13/4fea13c4d55a8b0a9e2b9903cdb09c34.js',
        ],
        'popunder' => [
            'label' => 'Pop-under / smartlink',
            'description' => 'Smartlink URL — opens on click in lab; do not enable sitewide without observation.',
            'type' => 'smartlink',
            'url' => 'https://www.profitableratecpmnetwork.com/xsjja7i0?key=7e680ac1f9ce8e5547eb972920f15f50',
        ],
    ],

];
