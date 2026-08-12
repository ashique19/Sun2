<?php

namespace App\Services\Admin;

use App\Models\Material;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductUnitCostService
{
    /**
     * Recalculate purchase_price (primary material) and unit_cost (total) for a product.
     *
     * - purchase_price = primary material line cost (qty × material unit_cost), or unchanged when no materials
     * - unit_cost = all material lines + cost heads (or purchase_price + heads when no materials)
     */
    public function recalculate(Product $product): Product
    {
        $product->loadMissing(['materials', 'costHeads']);

        $materialsTotal = 0.0;
        $primaryTotal = null;

        foreach ($product->materials as $material) {
            $qty = (float) $material->pivot->quantity;
            $line = round($qty * (float) $material->unit_cost, 2);
            $materialsTotal += $line;

            if ($material->pivot->is_primary) {
                $primaryTotal = ($primaryTotal ?? 0.0) + $line;
            }
        }

        $headsTotal = round((float) $product->costHeads->sum('amount'), 2);

        if ($product->materials->isNotEmpty()) {
            if ($primaryTotal === null) {
                $first = $product->materials->first();
                $primaryTotal = round((float) $first->pivot->quantity * (float) $first->unit_cost, 2);
            }

            $product->purchase_price = round($primaryTotal, 2);
            $product->unit_cost = round($materialsTotal + $headsTotal, 2);
        } else {
            $product->unit_cost = round((float) $product->purchase_price + $headsTotal, 2);
        }

        $product->save();

        return $product->refresh();
    }

    /**
     * Recalculate every product that uses this material.
     *
     * @return int Number of products updated
     */
    public function recalculateForMaterial(Material $material): int
    {
        $productIds = $material->products()->pluck('products.id');
        $count = 0;

        foreach ($productIds as $productId) {
            $product = Product::query()->find($productId);
            if ($product) {
                $this->recalculate($product);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Receive stock and update moving-average unit cost, then recalc dependent products.
     *
     * @return array{material: Material, products_updated: int}
     */
    public function receiveStock(Material $material, float $quantity, float $totalCost): array
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        if ($totalCost < 0) {
            throw new \InvalidArgumentException('Total cost cannot be negative.');
        }

        return DB::transaction(function () use ($material, $quantity, $totalCost) {
            $material->refresh();
            $oldQty = max(0.0, (float) $material->stock_quantity);
            $oldCost = (float) $material->unit_cost;
            $newQty = $oldQty + $quantity;

            $material->unit_cost = $newQty > 0
                ? round((($oldQty * $oldCost) + $totalCost) / $newQty, 2)
                : round($totalCost / $quantity, 2);
            $material->stock_quantity = round($newQty, 3);
            $material->save();

            $updated = $this->recalculateForMaterial($material);

            return [
                'material' => $material->refresh(),
                'products_updated' => $updated,
            ];
        });
    }

    /**
     * Unit cost used for COGS snapshots (total, with fallback to main purchase_price).
     */
    public function effectiveUnitCost(Product $product): float
    {
        if ($product->unit_cost !== null && $product->unit_cost !== '') {
            return round((float) $product->unit_cost, 2);
        }

        return round((float) $product->purchase_price, 2);
    }

    /**
     * Copy the product's current purchase_price + effective unit_cost onto matching order lines.
     *
     * Skips cancelled/returned orders — those should not pick up contribution COGS from a
     * backfill (revenue is usually ৳0; inventing cost makes P/L look wrongly negative when
     * returned_quantity was never set).
     *
     * Returns the number of matching lines (not PDO "rows changed"). MySQL does not count
     * rows rewritten to the same values, which made already-saved syncs look like "0 lines".
     *
     * @return int Number of order_products rows targeted
     */
    public function syncSnapshotsToOrderProducts(Product $product, ?int $onlyOrderId = null): int
    {
        $query = OrderProduct::query()
            ->where('order_products.product_id', $product->id)
            ->whereHas('order', function ($orderQuery) use ($onlyOrderId): void {
                $orderQuery->whereNotIn('status', ['cancelled', 'returned']);

                if ($onlyOrderId !== null) {
                    $orderQuery->whereKey($onlyOrderId);
                }
            });

        $ids = (clone $query)->orderBy('order_products.id')->pluck('order_products.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        OrderProduct::query()->whereIn('id', $ids)->update([
            'purchase_price' => round((float) $product->purchase_price, 2),
            'unit_cost' => $this->effectiveUnitCost($product),
        ]);

        return $ids->count();
    }

    /**
     * When a product has no unit cost, copy purchase/unit cost from its newest
     * non-cancelled/returned order line that already has a COGS snapshot.
     *
     * Skips products with BOM materials (those costs are owned by materials).
     */
    public function backfillMissingFromOrderSnapshots(Product $product): bool
    {
        $product->loadMissing('materials');

        if ($this->effectiveUnitCost($product) >= 0.01) {
            return false;
        }

        if ($product->materials->isNotEmpty()) {
            return false;
        }

        /** @var OrderProduct|null $line */
        $line = OrderProduct::query()
            ->where('product_id', $product->id)
            ->where(function ($query): void {
                $query->where('unit_cost', '>=', 0.01)
                    ->orWhere('purchase_price', '>=', 0.01);
            })
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery->whereNotIn('status', ['cancelled', 'returned', Order::STATUS_DRAFT]);
            })
            ->orderByDesc('id')
            ->first();

        if ($line === null || $line->effectiveUnitCost() < 0.01) {
            return false;
        }

        $unitCost = $line->effectiveUnitCost();
        $purchase = round((float) $line->purchase_price, 2);

        if ($purchase < 0.01) {
            $purchase = $unitCost;
        }

        if ($purchase > $unitCost) {
            $purchase = $unitCost;
        }

        $other = max(0.0, round($unitCost - $purchase, 2));

        $this->applyPurchaseAndOther($product, $purchase, $other);

        return $this->effectiveUnitCost($product->fresh()) >= 0.01;
    }

    /**
     * For each linked product on the order that still has ৳0 catalog cost, try to
     * fill it from any order-line snapshot for that product.
     *
     * @return int Number of products updated
     */
    public function backfillMissingProductsFromOrder(Order $order): int
    {
        $order->loadMissing('items.product.materials');
        $updated = 0;
        $seen = [];

        foreach ($order->items as $item) {
            if (! $item->product_id || ! $item->product instanceof Product) {
                continue;
            }

            $productId = (int) $item->product_id;

            if (isset($seen[$productId])) {
                continue;
            }

            $seen[$productId] = true;

            if ($this->backfillMissingFromOrderSnapshots($item->product)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Apply simple purchase + other overhead (no BOM edit). Replaces cost heads with a single "Other" head.
     */
    public function applyPurchaseAndOther(Product $product, float $purchasePrice, float $otherCost): Product
    {
        $purchasePrice = max(0, round($purchasePrice, 2));
        $otherCost = max(0, round($otherCost, 2));

        $product->loadMissing('materials');

        if ($product->materials->isEmpty()) {
            $product->purchase_price = $purchasePrice;
            $product->save();
        }

        $product->costHeads()->delete();

        if ($otherCost > 0) {
            $product->costHeads()->create([
                'name' => 'Other',
                'amount' => $otherCost,
                'sort_order' => 0,
            ]);
        }

        return $this->recalculate($product->fresh());
    }
}
