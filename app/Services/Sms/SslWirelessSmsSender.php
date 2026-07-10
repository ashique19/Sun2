<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SslWirelessSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        $config = config('sms.ssl_wireless');

        if (! $config['api_token'] || ! $config['sid']) {
            throw new RuntimeException('SSL Wireless SMS is not configured.');
        }

        $response = Http::asJson()
            ->post($config['api_url'], [
                'api_token' => $config['api_token'],
                'sid' => $config['sid'],
                'msisdn' => $phone,
                'sms' => $message,
                'csms_id' => (string) str()->uuid(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('SMS gateway request failed: '.$response->body());
        }
    }
}
