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
    | Host for Adsterra High Performance / iframe banner invoke.js
    | (dashboard may show highrevenueformat.com or similar).
    */
    'invoke_host' => env('ADSTERRA_INVOKE_HOST', 'www.highrevenueformat.com'),

    /*
    |--------------------------------------------------------------------------
    | Banner / iframe units (atOptions + invoke.js)
    |--------------------------------------------------------------------------
    */

    'banners' => [
        'banner_728' => [
            'label' => '728×90 Leaderboard',
            'description' => 'Wide desktop banner — common above content.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_BANNER_728', '6749cdd1ebf2dbcda3384c9f4c4f8cfb'),
            'width' => 728,
            'height' => 90,
            'format' => 'iframe',
        ],
        'banner_468' => [
            'label' => '468×60 Banner',
            'description' => 'Classic mid-width banner.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_BANNER_468', 'fd48224ebf3f09fd56ece982c1585ddf'),
            'width' => 468,
            'height' => 60,
            'format' => 'iframe',
        ],
        'banner_300' => [
            'label' => '300×250 Medium rectangle',
            'description' => 'Sidebar / in-content rectangle — works on mobile and desktop.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_BANNER_300', 'a356eb5486bfece119efb08195fb4a25'),
            'width' => 300,
            'height' => 250,
            'format' => 'iframe',
        ],
        'banner_320' => [
            'label' => '320×50 Mobile banner',
            'description' => 'Compact mobile strip.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_BANNER_320', '2b562aa780f28739eee1965844207030'),
            'width' => 320,
            'height' => 50,
            'format' => 'iframe',
        ],
        'skyscraper_160_600' => [
            'label' => '160×600 Wide skyscraper',
            'description' => 'Tall sidebar unit — best on desktop layouts.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_SKY_160_600', 'e142d88c96eac091e3874ed87e55e37c'),
            'width' => 160,
            'height' => 600,
            'format' => 'iframe',
        ],
        'skyscraper_160_300' => [
            'label' => '160×300 Half skyscraper',
            'description' => 'Shorter sidebar skyscraper.',
            'type' => 'atoptions',
            'key' => env('ADSTERRA_SLOT_SKY_160_300', '4b1ba3480be15d58894a81dd7321ed51'),
            'width' => 160,
            'height' => 300,
            'format' => 'iframe',
        ],
        'native' => [
            'label' => 'Native banner',
            'description' => 'Container-based native unit (profitableratecpmnetwork).',
            'type' => 'native_container',
            'key' => env('ADSTERRA_SLOT_NATIVE', '0150ba9fc718f1f7f103b72a3757ae25'),
            'script_src' => env(
                'ADSTERRA_NATIVE_SCRIPT_SRC',
                'https://pl31110128.profitableratecpmnetwork.com/0150ba9fc718f1f7f103b72a3757ae25/invoke.js',
            ),
            'width' => 300,
            'height' => 250,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Script / URL units (social bar, popunder, smartlink)
    |--------------------------------------------------------------------------
    */

    'scripts' => [
        'social_bar' => [
            'label' => 'Social bar',
            'description' => 'Sticky social-bar style script — observe placement and intensity.',
            'type' => 'script_src',
            'src' => env(
                'ADSTERRA_SCRIPT_SOCIAL_BAR',
                'https://pl31110125.profitableratecpmnetwork.com/2e/28/d6/2e28d6b523d7ac452ad571b5139de0eb.js',
            ),
        ],
        'in_page_push' => [
            'label' => 'In-page push / sticky',
            'description' => 'Second script unit from Adsterra — confirm label in dashboard if unsure.',
            'type' => 'script_src',
            'src' => env(
                'ADSTERRA_SCRIPT_IN_PAGE',
                'https://pl31110126.profitableratecpmnetwork.com/4f/ea/13/4fea13c4d55a8b0a9e2b9903cdb09c34.js',
            ),
        ],
        'popunder' => [
            'label' => 'Pop-under / smartlink',
            'description' => 'Smartlink URL — opens on click in lab; do not enable sitewide without observation.',
            'type' => 'smartlink',
            'url' => env(
                'ADSTERRA_POPUNDER_URL',
                'https://www.profitableratecpmnetwork.com/xsjja7i0?key=7e680ac1f9ce8e5547eb972920f15f50',
            ),
        ],
    ],

];
