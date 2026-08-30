<?php

namespace Tests\Feature;

use App\Contracts\Sms\SmsSender;
use App\Services\Sms\LoggingSmsSender;
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
        Config::set('sms.from', 'Sundoritoma');
        Config::set('sms.mimsms', [
            'api_url' => 'https://api.mimsms.com/api/V2/SMS',
            'username' => 'shop@example.com',
            'api_key' => 'secret-api-key',
            'sender_name' => 'Sundoritoma',
        ]);
    }

    public function test_resolves_mimsms_driver_from_container(): void
    {
        $this->assertInstanceOf(LoggingSmsSender::class, app(SmsSender::class));
    }

    public function test_sends_otp_sms_as_transactional_with_normalized_mobile_number(): void
    {
        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(SmsSender::class)->send('01627237432', 'Your Sundoritoma order confirmation OTP is 123456. Do not share this code.');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.mimsms.com/api/V2/SMS'
                && $request['UserName'] === 'shop@example.com'
                && $request['ApiKey'] === 'secret-api-key'
                && $request['MobileNumber'] === '8801627237432'
                && $request['SenderName'] === 'Sundoritoma'
                && $request['TransactionType'] === 'T'
                && $request['Message'] === 'Your Sundoritoma order confirmation OTP is 123456. Do not share this code.'
                && ! array_key_exists('CampaignId', $request->data());
        });
    }

    public function test_throws_when_mimsms_is_not_configured(): void
    {
        Config::set('sms.mimsms.api_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiMSMS is not configured.');

        app(SmsSender::class)->send('01627237432', 'Your Sundoritoma OTP is 123456.');
    }

    public function test_throws_when_otp_message_is_missing_brand_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MiMSMS OTP messages must include the brand name (Sundoritoma) in the message body.');

        app(SmsSender::class)->send('01627237432', 'Your OTP is 123456.');
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

        app(SmsSender::class)->send('01627237432', 'Your Sundoritoma OTP is 123456.');
    }

    public function test_sends_promotional_sms_as_type_p_with_optional_campaign_id(): void
    {
        Config::set('sms.mimsms.promotional_transaction_type', 'P');
        Config::set('sms.mimsms.promotional_campaign_id', null);

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(SmsSender::class)->sendPromotional(
            '01627237432',
            'সুন্দরিতমায় নতুন অফার চলছে!',
            'spring-sale',
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.mimsms.com/api/V2/SMS'
                && $request['TransactionType'] === 'P'
                && $request['CampaignId'] === 'spring-sale'
                && $request['Message'] === 'সুন্দরিতমায় নতুন অফার চলছে!'
                && $request['MobileNumber'] === '8801627237432';
        });
    }

    public function test_promotional_sms_does_not_require_brand_name_in_body(): void
    {
        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        app(SmsSender::class)->sendPromotional('01627237432', 'শুধু অফার বার্তা');

        Http::assertSent(fn (Request $request): bool => $request['TransactionType'] === 'P'
            && ! array_key_exists('CampaignId', $request->data()));
    }
}
