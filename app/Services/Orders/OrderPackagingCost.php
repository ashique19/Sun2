<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * Direct attributable packaging cost for an order.
 *
 * Rates by order date (placed_at, else created_at):
 * - 2025-01-01+: first piece ৳21, +৳11 each extra (all products)
 * - 2024–2023: first ৳35, +৳17 each extra; saree/handbag ৳48/piece
 * - 2022–2021: first ৳45, +৳21 each extra; saree/handbag ৳48/piece
 * - 2020 and earlier: first ৳30, +৳15 each extra; saree/handbag ৳48/piece
 */
class OrderPackagingCost
{
    public function __construct(
        private OrderEmptyProductDefaults $emptyProductDefaults = new OrderEmptyProductDefaults,
    ) {}

    /**
     * @return array{first: float, extra: float, special_per_piece: float|null}
     */
    public function rateCardForDate(?CarbonInterface $at): array
    {
        $at ??= now();
        $year = (int) $at->year;

        if ($at->greaterThanOrEqualTo('2025-01-01')) {
            return ['first' => 21.0, 'extra' => 11.0, 'special_per_piece' => null];
        }

        if ($year >= 2023) {
            return ['first' => 35.0, 'extra' => 17.0, 'special_per_piece' => 48.0];
        }

        if ($year >= 2021) {
            return ['first' => 45.0, 'extra' => 21.0, 'special_per_piece' => 48.0];
        }

        return ['first' => 30.0, 'extra' => 15.0, 'special_per_piece' => 48.0];
    }

    /**
     * Current (2025+) qty-only schedule used when no order date is available.
     */
    public function defaultForQuantity(int $productQuantity): float
    {
        return $this->standardTierTotal($productQuantity, 21.0, 11.0);
    }

    public function productQuantity(Order $order): int
    {
        return $this->emptyProductDefaults->sellableQuantity($order);
    }

    public function defaultFor(Order $order): float
    {
        return $this->estimateFor($order);
    }

    /**
     * Full packaging estimate from line items + order date.
     * Orders with no product quantity use a flat ৳21 packaging cost.
     */
    public function estimateFor(Order $order): float
    {
        $order->loadMissing(['items.product.category']);

        $at = $order->placed_at ?? $order->created_at ?? now();
        $rates = $this->rateCardForDate($at instanceof CarbonInterface ? $at : now());

        $specialQty = 0;
        $standardQty = 0;

        foreach ($order->items as $item) {
            if ($this->emptyProductDefaults->isPlaceholderLine($item)) {
                continue;
            }

            $qty = max(0, (int) $item->quantity);

            if ($qty < 1) {
                continue;
            }

            if ($rates['special_per_piece'] !== null && $this->isSpecialPackagingLine($item)) {
                $specialQty += $qty;
            } else {
                $standardQty += $qty;
            }
        }

        if ($specialQty < 1 && $standardQty < 1) {
            return OrderEmptyProductDefaults::PACKAGING;
        }

        $total = 0.0;

        if ($specialQty > 0 && $rates['special_per_piece'] !== null) {
            $total += $specialQty * $rates['special_per_piece'];
        }

        $total += $this->standardTierTotal($standardQty, $rates['first'], $rates['extra']);

        return round($total, 2);
    }

    /**
     * Prefer a previously saved packaging cost; otherwise the estimate.
     */
    public function suggestedAmount(Order $order): float
    {
        $current = round((float) ($order->packaging_cost ?? 0), 2);

        if ($current > 0) {
            return $current;
        }

        return $this->estimateFor($order);
    }

    public function apply(Order $order, float $amount): void
    {
        $order->packaging_cost = round(max(0.0, $amount), 2);
        $order->save();
    }

    public function isSpecialPackagingLine(OrderProduct $item): bool
    {
        $product = $item->relationLoaded('product') ? $item->product : $item->product()->with('category')->first();

        return $this->looksLikeSareeOrHandbag(
            (string) $item->name,
            $product,
        );
    }

    public function looksLikeSareeOrHandbag(string $lineName, ?Product $product = null): bool
    {
        $needles = ['saree', 'sharee', 'handbag', 'hand-bag', 'hand bag'];

        $haystacks = array_filter([
            $lineName,
            $product?->name,
            $product?->slug,
            $product?->category?->name,
            $product?->category?->slug,
        ], fn ($value) => filled($value));

        foreach ($haystacks as $text) {
            $normalized = mb_strtolower(trim((string) $text));

            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function standardTierTotal(int $quantity, float $first, float $extra): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return round($first + (($quantity - 1) * $extra), 2);
    }
}
