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

    /** MiMSMS promotional / marketing SMS (type P). */
    private const TRANSACTION_TYPE_PROMOTIONAL = 'P';

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

        $this->post($config['api_url'], $payload);
    }

    public function sendPromotional(string $phone, string $message, ?string $campaignId = null): void
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
            'TransactionType' => (string) ($config['promotional_transaction_type'] ?? self::TRANSACTION_TYPE_PROMOTIONAL),
            'Message' => $message,
        ];

        $campaign = $campaignId !== null && $campaignId !== ''
            ? $campaignId
            : ($config['promotional_campaign_id'] ?? null);

        if (is_string($campaign) && $campaign !== '') {
            $payload['CampaignId'] = $campaign;
        }

        $this->post($config['api_url'], $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $apiUrl, array $payload): void
    {
        $response = Http::asJson()
            ->acceptJson()
            ->post($apiUrl, $payload);

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
