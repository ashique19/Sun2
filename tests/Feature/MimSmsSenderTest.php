<?php

namespace Tests\Feature;

use App\Contracts\Sms\SmsSender;
use App\Services\Sms\MimSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MimSmsSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('sms.driver', 'mimsms');
        Config::set('sms.mimsms', [
            'api_url' => 'https://api.mimsms.com/api/V2/SMS',
            'username' => 'shop@example.com',
            'api_key' => 'secret-api-key',
            'sender_name' => 'Sundoritoma',
            'transaction_type' => 'T',
            'campaign_id' => null,
        ]);
    }

    public function test_resolves_mimsms_driver_from_container(): void
    {
        $this->assertInstanceOf(MimSmsSender::class, app(SmsSender::class));
    }

    public function test_sends_sms_with_normalized_mobile_number(): void
    {
        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(SmsSender::class)->send('01627237432', 'Your OTP is 123456');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.mimsms.com/api/V2/SMS'
                && $request['UserName'] === 'shop@example.com'
                && $request['ApiKey'] === 'secret-api-key'
                && $request['MobileNumber'] === '8801627237432'
                && $request['SenderName'] === 'Sundoritoma'
                && $request['TransactionType'] === 'T'
                && $request['Message'] === 'Your OTP is 123456'
                && ! array_key_exists('CampaignId', $request->data());
        });
    }

    public function test_throws_when_mimsms_is_not_configured(): void
    {
        Config::set('sms.mimsms.api_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiMSMS is not configured.');

        app(SmsSender::class)->send('01627237432', 'Hello');
    }

    public function test_throws_when_gateway_rejects_message(): void
    {
        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Failed',
                'responseResult' => 'Invalid sender name',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiMSMS gateway rejected the message: Invalid sender name');

        app(SmsSender::class)->send('01627237432', 'Hello');
    }

    public function test_includes_campaign_id_for_promotional_messages(): void
    {
        Config::set('sms.mimsms.transaction_type', 'P');
        Config::set('sms.mimsms.campaign_id', '42');

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
            ]),
        ]);

        app(SmsSender::class)->send('01627237432', 'Sale ends tonight');

        Http::assertSent(fn (Request $request): bool => $request['TransactionType'] === 'P'
            && $request['CampaignId'] === '42');
    }
}
