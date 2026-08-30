<?php

namespace App\Jobs;

use App\Contracts\Sms\SmsSender;
use App\Services\Sms\SmsDispatchService;
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

    public function handle(SmsDispatchService $sms, SmsSender $sender): void
    {
        if ($this->promotional) {
            $sender->sendPromotional($this->phone, $this->message);

            return;
        }

        $sms->sendTransactional($this->phone, $this->message);
    }
}
