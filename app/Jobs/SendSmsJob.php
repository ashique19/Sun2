<?php

namespace App\Jobs;

use App\Contracts\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $phone,
        public string $message,
        public bool $promotional = false,
    ) {}

    public function handle(SmsSender $sms): void
    {
        if ($this->promotional) {
            $sms->sendPromotional($this->phone, $this->message);

            return;
        }

        $sms->send($this->phone, $this->message);
    }
}
