<?php

namespace App\Services\Couriers\Webhooks;

use App\Models\Courier;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class PathaoWebhookProcessor
{
    public function __construct(
        private readonly CourierWebhookSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): bool
    {
        $event = (string) ($payload['event'] ?? '');

        if ($event === 'webhook_integration') {
            return true;
        }

        if ($event === '') {
            return false;
        }

        $order = $this->support->findOrder($payload, ['merchant_order_id'], ['consignment_id']);

        if (! $order) {
            Log::warning('Pathao webhook: order not found.', [
                'event' => $event,
                'merchant_order_id' => $payload['merchant_order_id'] ?? null,
                'consignment_id' => $payload['consignment_id'] ?? null,
            ]);

            return true;
        }

        $courierId = Courier::query()->where('slug', 'pathao')->value('id');

        $this->support->process($order, $courierId, $payload, function (Order $order) use ($event, $payload) {
            $message = $this->messageForEvent($event, $payload);
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

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function messageForEvent(string $event, array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));

        return match ($event) {
            'order.delivered' => 'Pathao: parcel delivered.'.($reason !== '' ? ' '.$reason : ''),
            'order.partial-delivery', 'order.partial_delivered' => 'Pathao: partial delivery.'.($reason !== '' ? ' '.$reason : ''),
            'order.returned', 'order.returned-to-merchant' => 'Pathao: parcel returned.'.($reason !== '' ? ' '.$reason : ''),
            'order.cancelled' => 'Pathao: order cancelled.',
            'order.delivery-failed', 'order.delivery_failed' => 'Pathao: delivery failed.'.($reason !== '' ? ' '.$reason : ''),
            'order.on-hold', 'order.on_hold' => 'Pathao: on hold.'.($reason !== '' ? ' '.$reason : ''),
            'order.paid' => 'Pathao: COD settled.'.(isset($payload['invoice_id']) ? ' Invoice: '.$payload['invoice_id'] : ''),
            default => 'Pathao: '.$event,
        };
    }

    private function mapEvent(string $event): ?string
    {
        return match ($event) {
            'order.delivered', 'order.partial-delivery', 'order.partial_delivered' => 'delivered',
            'order.returned', 'order.returned-to-merchant', 'order.paid-return', 'order.paid_return' => 'returned',
            'order.cancelled' => 'cancelled',
            'order.picked', 'order.in_transit', 'order.in-transit', 'order.assigned-for-pickup',
            'order.pickup_requested', 'order.accepted', 'order.created' => 'dispatched',
            default => null,
        };
    }
}
