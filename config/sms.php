<?php

return [
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', 'Sundoritoma'),

    'ssl_wireless' => [
        'api_url' => env('SMS_SSL_WIRELESS_URL', 'https://smsplus.sslwireless.com/api/v3/send-sms'),
        'api_token' => env('SMS_SSL_WIRELESS_TOKEN'),
        'sid' => env('SMS_SSL_WIRELESS_SID'),
    ],
];
