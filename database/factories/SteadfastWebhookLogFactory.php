<?php

namespace Database\Factories;

use App\Models\SteadfastWebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteadfastWebhookLog>
 */
class SteadfastWebhookLogFactory extends Factory
{
    protected $model = SteadfastWebhookLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoice = 'WH-'.fake()->unique()->numerify('####');
        $consignmentId = (string) fake()->numberBetween(100000, 999999);
        $payload = [
            'notification_type' => 'tracking_update',
            'invoice' => $invoice,
            'consignment_id' => (int) $consignmentId,
            'tracking_message' => 'Arrived at hub',
            'updated_at' => now()->toDateTimeString(),
        ];

        return [
            'order_id' => null,
            'outcome' => SteadfastWebhookLog::OUTCOME_UNMATCHED,
            'http_status' => 200,
            'notification_type' => 'tracking_update',
            'invoice' => $invoice,
            'consignment_id' => $consignmentId,
            'tracking_id' => null,
            'idempotency_key' => fake()->uuid(),
            'user_agent' => 'Steadfast-Webhook/1.0',
            'content_type' => 'application/json',
            'has_signature' => true,
            'raw_body' => json_encode($payload),
            'payload' => $payload,
            'error' => null,
        ];
    }
}
