<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoggingSmsSender implements SmsSender
{
    public function __construct(private SmsSender $inner) {}

    public function send(string $phone, string $message): void
    {
        $this->sendWithLogging('transactional', $phone, fn () => $this->inner->send($phone, $message));
    }

    public function sendPromotional(string $phone, string $message, ?string $campaignId = null): void
    {
        $this->sendWithLogging(
            'promotional',
            $phone,
            fn () => $this->inner->sendPromotional($phone, $message, $campaignId),
            $campaignId,
        );
    }

    /**
     * @param  callable(): void  $send
     */
    private function sendWithLogging(string $type, string $phone, callable $send, ?string $campaignId = null): void
    {
        $context = [
            'type' => $type,
            'driver' => config('sms.driver'),
            'phone' => $this->maskPhone($phone),
        ];

        if ($campaignId !== null && $campaignId !== '') {
            $context['campaign_id'] = $campaignId;
        }

        Log::info('SMS send attempt', $context);

        if (app()->hasDebugModeEnabled()) {
            Log::info('SMS send skipped (APP_DEBUG is enabled)', $context);

            return;
        }

        try {
            $send();

            Log::info('SMS send succeeded', $context);
        } catch (Throwable $e) {
            Log::error('SMS send failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return '***';
        }

        return '***'.substr($digits, -4);
    }
}
