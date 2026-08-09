<?php

namespace App\Services\Admin;

use App\Models\Material;
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
}
