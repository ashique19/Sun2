<?php

namespace Tests\Unit;

use App\Services\Couriers\SteadfastWebhookPayload;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SteadfastWebhookPayloadTest extends TestCase
{
    #[Test]
    public function missing_notification_type_with_a_tracking_message_is_a_tracking_update(): void
    {
        $event = SteadfastWebhookPayload::normalize([
            'consignment_id' => 12345,
            'invoice' => 'ORD-1',
            'tracking_message' => 'Arrived at sorting center',
            'updated_at' => '2026-11-03 13:15:00',
        ]);

        $this->assertSame('tracking_update', $event['notification_type']);
        $this->assertSame('Arrived at sorting center', $event['tracking_message']);
    }

    #[Test]
    public function official_laravel_package_shape_is_delivery_status(): void
    {
        $event = SteadfastWebhookPayload::normalize([
            'consignment_id' => 12345,
            'invoice' => 'ORD-1',
            'status' => 'delivered',
            'cod_amount' => 1500,
            'updated_at' => '2026-11-03 12:45:30',
        ]);

        $this->assertSame('delivery_status', $event['notification_type']);
        $this->assertSame('delivered', $event['status']);
    }

    #[Test]
    public function data_wrapper_is_flattened(): void
    {
        $events = SteadfastWebhookPayload::eventsFrom([
            'status' => 200,
            'data' => [
                'notification_type' => 'tracking_update',
                'consignment_id' => 277193413,
                'invoice' => 'ORD-9',
                'tracking_message' => 'Out for delivery',
            ],
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('tracking_update', $events[0]['notification_type']);
        $this->assertSame(277193413, $events[0]['consignment_id']);
        $this->assertSame('ORD-9', $events[0]['invoice']);
        $this->assertSame('Out for delivery', $events[0]['tracking_message']);
    }

    #[Test]
    public function nested_consignment_fields_are_lifted(): void
    {
        $event = SteadfastWebhookPayload::normalize([
            'notification_type' => 'tracking_update',
            'consignment' => [
                'consignment_id' => 999,
                'invoice' => 'ORD-NEST',
            ],
            'note' => 'Rider assigned',
        ]);

        $this->assertSame(999, $event['consignment_id']);
        $this->assertSame('ORD-NEST', $event['invoice']);
        $this->assertSame('Rider assigned', $event['tracking_message']);
    }

    #[Test]
    public function consignment_update_with_wording_becomes_a_tracking_update(): void
    {
        $event = SteadfastWebhookPayload::normalize([
            'notification_type' => 'consignment_update',
            'invoice' => 'ORD-1',
            'tracking_message' => 'Address updated',
        ]);

        $this->assertSame('tracking_update', $event['notification_type']);
        $this->assertSame('consignment_update', $event['original_notification_type']);
        $this->assertSame('Address updated', $event['tracking_message']);
    }

    #[Test]
    public function status_type_is_used_when_status_is_a_sentence(): void
    {
        $event = SteadfastWebhookPayload::normalize([
            'consignment_id' => 1,
            'invoice' => 'ORD-1',
            'status' => 'Package arrived at hub',
            'status_type' => 'pending',
        ]);

        $this->assertSame('delivery_status', $event['notification_type']);
        $this->assertSame('pending', $event['status']);
        $this->assertSame('Package arrived at hub', $event['tracking_message']);
    }

    #[Test]
    public function a_list_of_events_is_normalized_individually(): void
    {
        $events = SteadfastWebhookPayload::eventsFrom([
            [
                'invoice' => 'ORD-1',
                'tracking_message' => 'At hub',
            ],
            [
                'invoice' => 'ORD-2',
                'status' => 'delivered',
            ],
        ]);

        $this->assertCount(2, $events);
        $this->assertSame('tracking_update', $events[0]['notification_type']);
        $this->assertSame('delivery_status', $events[1]['notification_type']);
    }

    #[Test]
    public function numeric_envelope_status_is_not_a_tracking_message(): void
    {
        $this->assertSame('', SteadfastWebhookPayload::trackingMessage([
            'status' => 200,
            'invoice' => 'ORD-1',
        ]));
    }
}
