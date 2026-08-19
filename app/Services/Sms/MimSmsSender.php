<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MimSmsSender implements SmsSender
{
    /** MiMSMS routes OTP through transactional SMS (type T), not promotional. */
    private const TRANSACTION_TYPE_OTP = 'T';

    public function send(string $phone, string $message): void
    {
        $config = config('sms.mimsms');

        if (! $config['username'] || ! $config['api_key'] || ! $config['sender_name']) {
            throw new RuntimeException('MiMSMS is not configured.');
        }

        $this->assertOtpMessageIncludesBrand($message);

        $payload = [
            'UserName' => $config['username'],
            'ApiKey' => $config['api_key'],
            'MobileNumber' => $this->formatMobileNumber($phone),
            'SenderName' => $config['sender_name'],
            'TransactionType' => self::TRANSACTION_TYPE_OTP,
            'Message' => $message,
        ];

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

    private function assertOtpMessageIncludesBrand(string $message): void
    {
        $brand = (string) config('sms.from');

        if ($brand === '' || str_contains(strtolower($message), strtolower($brand))) {
            return;
        }

        throw new RuntimeException(
            'MiMSMS OTP messages must include the brand name ('.$brand.') in the message body.',
        );
    }
}
