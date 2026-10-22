<?php

namespace Tests\Feature;

use App\Services\Couriers\SteadfastApiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastCreateOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'steadfast.api_key' => 'test-key',
            'steadfast.secret_key' => 'test-secret',
            'steadfast.base_url' => 'https://portal.packzy.com/api/v1',
        ]);
    }

    #[Test]
    public function create_order_sends_documented_auth_headers_once(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/create_order' => Http::response([
                'status' => 200,
                'message' => 'Consignment has been created successfully',
                'consignment' => [
                    'consignment_id' => 1424107,
                    'invoice' => 'ORD-10233',
                    'tracking_code' => '15A4B80AA',
                    'recipient_name' => 'Jahidul Hasan',
                    'recipient_phone' => '01712345678',
                    'cod_amount' => 1060,
                ],
            ], 200),
        ]);

        $response = app(SteadfastApiClient::class)->createOrder([
            'invoice' => 'ORD-10233',
            'recipient_name' => 'Jahidul Hasan',
            'recipient_phone' => '01712345678',
            'recipient_address' => 'House 17, Road 3/A, Dhanmondi',
            'cod_amount' => 1060,
            'note' => 'Call before 3 PM',
            'delivery_type' => 0,
        ]);

        $this->assertSame(1424107, $response['consignment']['consignment_id']);
        $this->assertSame('15A4B80AA', $response['consignment']['tracking_code']);

        Http::assertSent(function ($request) {
            $apiKeys = $request->header('Api-Key');
            $secretKeys = $request->header('Secret-Key');

            return $request->url() === 'https://portal.packzy.com/api/v1/create_order'
                && $request->method() === 'POST'
                && $request->hasHeader('Api-Key', 'test-key')
                && $request->hasHeader('Secret-Key', 'test-secret')
                && $request->hasHeader('Content-Type', 'application/json')
                && is_array($apiKeys)
                && count($apiKeys) === 1
                && is_array($secretKeys)
                && count($secretKeys) === 1
                && $request['invoice'] === 'ORD-10233'
                && $request['recipient_phone'] === '01712345678'
                && $request['delivery_type'] === 0;
        });
    }

    #[Test]
    public function create_order_surfaces_401_without_dumping_html(): void
    {
        Http::fake([
            'portal.packzy.com/api/v1/create_order' => Http::response([
                'status' => 401,
                'message' => 'Unauthorized Access (invalid API credentials)',
            ], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Steadfast API error (401): Unauthorized Access (invalid API credentials)');

        app(SteadfastApiClient::class)->createOrder([
            'invoice' => 'ORD-10233',
            'recipient_name' => 'Jahidul Hasan',
            'recipient_phone' => '01712345678',
            'recipient_address' => 'House 17, Road 3/A, Dhanmondi',
            'cod_amount' => 1060,
        ]);
    }
}
