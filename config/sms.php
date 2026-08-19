<?php

return [
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', 'Sundoritoma'),

    'ssl_wireless' => [
        'api_url' => env('SMS_SSL_WIRELESS_URL', 'https://smsplus.sslwireless.com/api/v3/send-sms'),
        'api_token' => env('SMS_SSL_WIRELESS_TOKEN'),
        'sid' => env('SMS_SSL_WIRELESS_SID'),
    ],

    // OTP-only: checkout and password-reset codes use transactional type T on MiMSMS.
    'mimsms' => [
        'api_url' => env('SMS_MIMSMS_URL', 'https://api.mimsms.com/api/V2/SMS'),
        'username' => env('SMS_MIMSMS_USERNAME'),
        'api_key' => env('SMS_MIMSMS_API_KEY'),
        'sender_name' => env('SMS_MIMSMS_SENDER_NAME', env('SMS_FROM', 'Sundoritoma')),
    ],
];
