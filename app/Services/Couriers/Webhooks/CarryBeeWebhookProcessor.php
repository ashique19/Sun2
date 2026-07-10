<?php

namespace App\Services\Couriers\Webhooks;

use App\Models\Courier;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class CarryBeeWebhookProcessor
{
    public function __construct(
        private readonly CourierWebhookSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');

        if ($event === '') {
            return;
        }

        $order = $this->support->findOrder($payload, ['merchant_order_id'], ['consignment_id']);

        if (! $order) {
            Log::warning('CarryBee webhook: order not found.', [
                'event' => $event,
                'merchant_order_id' => $payload['merchant_order_id'] ?? null,
                'consignment_id' => $payload['consignment_id'] ?? null,
            ]);

            return;
        }

        $courierId = Courier::query()->where('slug', 'carrybee')->value('id');
        $message = $this->messageForEvent($event, $payload);

        $this->support->process($order, $courierId, $payload, function (Order $order) use ($event, $message, $payload) {
            $mapped = $this->mapEvent($event);

            if ($mapped === 'dispatched' && in_array($order->status, ['new', 'confirmed'], true)) {
                $this->support->applyStatus($order, 'dispatched', $message, $payload);

                return;
            }

            if ($mapped !== null) {
                $this->support->applyStatus($order, $mapped, $message, $payload);

                return;
            }

            $this->support->recordHistory($order, $order->status, $message);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function messageForEvent(string $event, array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));

        return match ($event) {
            'order.delivered' => 'CarryBee: parcel delivered.',
            'order.returned-to-merchant' => 'CarryBee: returned to merchant.',
            'order.delivery-failed' => 'CarryBee: delivery failed.'.($reason !== '' ? ' '.$reason : ''),
            'order.paid' => 'CarryBee: COD settled.'.(isset($payload['invoice_id']) ? ' Invoice: '.$payload['invoice_id'] : ''),
            default => 'CarryBee: '.str_replace('order.', '', $event),
        };
    }

    private function mapEvent(string $event): ?string
    {
        return match ($event) {
            'order.delivered' => 'delivered',
            'order.returned-to-merchant' => 'returned',
            'order.picked', 'order.in-transit', 'order.assigned-for-delivery',
            'order.at-the-sorting-hub', 'order.received-at-last-mile-hub' => 'dispatched',
            default => null,
        };
    }
}
