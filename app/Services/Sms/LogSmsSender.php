<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS sent', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }

    public function sendPromotional(string $phone, string $message, ?string $campaignId = null): void
    {
        Log::info('Promotional SMS sent', [
            'phone' => $phone,
            'message' => $message,
            'campaign_id' => $campaignId,
        ]);
    }
}
