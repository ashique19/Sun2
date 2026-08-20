<?php

namespace App\Contracts\Sms;

interface SmsSender
{
    public function send(string $phone, string $message): void;

    /**
     * Send a promotional / marketing SMS (MiMSMS type P when that driver is used).
     */
    public function sendPromotional(string $phone, string $message, ?string $campaignId = null): void;
}
