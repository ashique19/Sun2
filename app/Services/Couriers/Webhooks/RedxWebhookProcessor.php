<?php

namespace App\Services\Couriers\Webhooks;

use App\Models\Courier;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class RedxWebhookProcessor
{
    public function __construct(
        private readonly CourierWebhookSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $status = strtolower((string) ($payload['status'] ?? ''));

        if ($status === '') {
            return;
        }

        $order = $this->support->findOrder($payload, ['invoice_number'], ['tracking_number', 'tracking_id']);

        if (! $order) {
            Log::warning('RedX webhook: order not found.', [
                'status' => $status,
                'invoice_number' => $payload['invoice_number'] ?? null,
                'tracking_number' => $payload['tracking_number'] ?? null,
            ]);

            return;
        }

        $courierId = Courier::query()->where('slug', 'redx')->value('id');
        $message = trim((string) ($payload['message_en'] ?? $payload['message_bn'] ?? 'RedX status: '.$status));

        $this->support->process($order, $courierId, $payload, function (Order $order) use ($status, $message, $payload) {
            $mapped = $this->mapStatus($status);

            if ($mapped === 'dispatched' && ! in_array($order->status, ['new', 'confirmed', 'dispatched'], true)) {
                $this->support->recordHistory($order, $order->status, $message);

                return;
            }

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

    private function mapStatus(string $status): ?string
    {
        return match ($status) {
            'delivered' => 'delivered',
            'returned', 'agent-returning' => 'returned',
            'ready-for-delivery', 'delivery-in-progress', 'agent-area-change' => 'dispatched',
            default => null,
        };
    }
}
