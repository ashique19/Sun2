<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class OrderStockService
{
    public function reserve(int $productId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $this->adjust($productId, -$quantity);
    }

    public function release(int $productId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $this->adjust($productId, $quantity);
    }

    public function releaseOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->product_id) {
                $this->release((int) $item->product_id, (int) $item->quantity);
            }
        }
    }

    /**
     * @param  array<int, int>  $oldQuantities  product_id => quantity
     * @param  array<int, int>  $newQuantities  product_id => quantity
     */
    public function syncQuantities(array $oldQuantities, array $newQuantities): void
    {
        $productIds = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));

        foreach ($productIds as $productId) {
            $old = (int) ($oldQuantities[$productId] ?? 0);
            $new = (int) ($newQuantities[$productId] ?? 0);
            $delta = $new - $old;

            if ($delta > 0) {
                $this->reserve((int) $productId, $delta);
            } elseif ($delta < 0) {
                $this->release((int) $productId, abs($delta));
            }
        }
    }

    /**
     * @return array<int, int>
     */
    public function quantitiesFromOrder(Order $order): array
    {
        $quantities = [];

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $productId = (int) $item->product_id;
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item->quantity;
        }

        return $quantities;
    }

    /**
     * @param  list<array{product_id:int|null,quantity:int}>  $lines
     * @return array<int, int>
     */
    public function quantitiesFromLines(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            if (empty($line['product_id'])) {
                continue;
            }

            $productId = (int) $line['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $line['quantity'];
        }

        return $quantities;
    }

    private function adjust(int $productId, int $delta): void
    {
        /** @var Product|null $product */
        $product = Product::query()->lockForUpdate()->find($productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'lines' => 'One or more products could not be found.',
            ]);
        }

        if ($delta < 0 && $product->stock_quantity < abs($delta)) {
            throw ValidationException::withMessages([
                'lines' => "Insufficient stock for “{$product->name}”. Available: {$product->stock_quantity}.",
            ]);
        }

        $product->increment('stock_quantity', $delta);
    }
}
