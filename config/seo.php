<?php

return [

    'site_name' => 'Sundoritoma',

    'default_title' => 'Sundoritoma — High-Quality Handmade Jewellery',

    'default_description' => 'High-quality handmade jewellery from Sundoritoma — German silver, brass, beads, and handcrafted collections. Cash on delivery and home delivery all over Bangladesh.',

    'default_image' => '/img/settings/favicon.png',

    'twitter_card' => 'summary_large_image',

    'organization' => [
        'email' => 'info@sundoritoma.com',
        'telephone' => '+8801880001255',
        'address_locality' => 'Dhaka',
        'address_country' => 'BD',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes that should not be indexed
    |--------------------------------------------------------------------------
    */
    'noindex_route_names' => [
        'cart',
        'checkout',
        'checkout.confirmation',
        'login',
        'register',
        'password.request',
        'password.reset',
        'account',
        'account.profile',
        'account.password',
        'account.orders',
        'account.orders.show',
        'account.wishlist',
        'search',
        'share.products',
    ],

];
