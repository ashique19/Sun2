<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MimSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        $config = config('sms.mimsms');

        if (! $config['username'] || ! $config['api_key'] || ! $config['sender_name']) {
            throw new RuntimeException('MiMSMS is not configured.');
        }

        $payload = [
            'UserName' => $config['username'],
            'ApiKey' => $config['api_key'],
            'MobileNumber' => $this->formatMobileNumber($phone),
            'SenderName' => $config['sender_name'],
            'TransactionType' => $config['transaction_type'],
            'Message' => $message,
        ];

        if ($config['transaction_type'] === 'P' && filled($config['campaign_id'])) {
            $payload['CampaignId'] = $config['campaign_id'];
        }

        $response = Http::asJson()
            ->acceptJson()
            ->post($config['api_url'], $payload);

        if (! $response->successful()) {
            throw new RuntimeException('MiMSMS gateway request failed: '.$response->body());
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'Success') {
            $detail = is_array($body)
                ? ($body['responseResult'] ?? $body['message'] ?? json_encode($body))
                : $response->body();

            throw new RuntimeException('MiMSMS gateway rejected the message: '.$detail);
        }
    }

    private function formatMobileNumber(string $phone): string
    {
        return '880'.PhoneNumber::normalize($phone);
    }
}
