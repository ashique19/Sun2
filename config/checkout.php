<?php

return [
    'dhaka_cities' => ['dhaka', 'ঢাকা'],

    'dhaka_city_delivery_upto_5' => (int) env('CHECKOUT_DHAKA_CITY_UPTO_5', 80),

    'dhaka_city_delivery_over_5' => (int) env('CHECKOUT_DHAKA_CITY_OVER_5', 150),

    'outside_delivery_upto_5' => (int) env('CHECKOUT_OUTSIDE_UPTO_5', 120),

    'outside_delivery_over_5' => (int) env('CHECKOUT_OUTSIDE_OVER_5', 200),

    'otp_ttl_minutes' => (int) env('CHECKOUT_OTP_TTL', 10),

    'otp_max_attempts' => 5,
];
