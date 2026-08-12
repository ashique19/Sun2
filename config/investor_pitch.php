<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Investor pitch share link
    |--------------------------------------------------------------------------
    |
    | Public URL: /share/investor-pitch/{token}
    | Visitors must enter share_password once per session.
    | Leave token or password empty to disable the public share route.
    |
    */

    'share_token' => env('INVESTOR_PITCH_SHARE_TOKEN', ''),

    'share_password' => env('INVESTOR_PITCH_SHARE_PASSWORD', ''),

];
