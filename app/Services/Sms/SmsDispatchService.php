<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;

/**
 * Sends transactional SMS synchronously so OTP delivery does not depend on a queue worker.
 */
class SmsDispatchService
{
    public function __construct(private SmsSender $sms) {}

    public function sendTransactional(string $phone, string $message): void
    {
        $this->sms->send($phone, $message);
    }
}
