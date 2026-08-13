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
            $material = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
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
     * Treat ~৳0 unit_cost as unset.
     */
    public function effectiveUnitCost(Product $product): float
    {
        if ($product->unit_cost !== null && $product->unit_cost !== '' && (float) $product->unit_cost >= 0.01) {
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
        $purchase = round((float) $product->purchase_price, 2);
        $unitCost = $this->effectiveUnitCost($product);
        $count = 0;

        OrderProduct::query()
            ->where('order_products.product_id', $product->id)
            ->whereHas('order', function ($orderQuery) use ($onlyOrderId): void {
                $orderQuery->whereNotIn('status', ['cancelled', 'returned']);

                if ($onlyOrderId !== null) {
                    $orderQuery->whereKey($onlyOrderId);
                }
            })
            ->orderBy('order_products.id')
            ->chunkById(500, function ($lines) use ($purchase, $unitCost, &$count): void {
                $ids = $lines->modelKeys();

                if ($ids === []) {
                    return;
                }

                OrderProduct::query()->whereIn('id', $ids)->update([
                    'purchase_price' => $purchase,
                    'unit_cost' => $unitCost,
                ]);

                $count += count($ids);
            }, column: 'order_products.id', alias: 'id');

        return $count;
    }

    /**
     * Snapshot COGS from a line: prefer unit_cost, else purchase_price.
     */
    public function lineSnapshotCost(OrderProduct $line): float
    {
        return $line->effectiveUnitCost();
    }

    /**
     * Find the newest usable cost snapshot for a product id and/or line name.
     */
    public function findCostSourceLine(?int $productId, ?string $lineName): ?OrderProduct
    {
        $base = OrderProduct::query()
            ->where(function ($query): void {
                $query->where('unit_cost', '>=', 0.01)
                    ->orWhere('purchase_price', '>=', 0.01);
            })
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery->whereNotIn('status', ['cancelled', 'returned', Order::STATUS_DRAFT]);
            });

        if ($productId) {
            /** @var OrderProduct|null $byProduct */
            $byProduct = (clone $base)
                ->where('product_id', $productId)
                ->orderByDesc('id')
                ->first();

            if ($byProduct && $this->lineSnapshotCost($byProduct) >= 0.01) {
                return $byProduct;
            }
        }

        $name = trim((string) $lineName);

        if ($name === '') {
            return null;
        }

        /** @var OrderProduct|null $byName */
        $byName = (clone $base)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->orderByDesc('id')
            ->first();

        if ($byName && $this->lineSnapshotCost($byName) >= 0.01) {
            return $byName;
        }

        // Line may display a short name while the catalog product has the full title.
        if ($productId) {
            return null;
        }

        $product = Product::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($product && $this->effectiveUnitCost($product) >= 0.01) {
            // Synthetic: return newest line for that product if any, else null —
            // caller can also resolve via findProductCostByName.
            return (clone $base)
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * @return array{purchase: float, unit_cost: float}|null
     */
    public function resolveCostSnapshot(?int $productId, ?string $lineName): ?array
    {
        if ($productId) {
            $product = Product::query()->find($productId);

            if ($product && $this->effectiveUnitCost($product) >= 0.01) {
                return [
                    'purchase' => round((float) $product->purchase_price, 2) >= 0.01
                        ? round((float) $product->purchase_price, 2)
                        : $this->effectiveUnitCost($product),
                    'unit_cost' => $this->effectiveUnitCost($product),
                ];
            }
        }

        $line = $this->findCostSourceLine($productId, $lineName);

        if ($line && $this->lineSnapshotCost($line) >= 0.01) {
            $unitCost = $this->lineSnapshotCost($line);
            $purchase = round((float) $line->purchase_price, 2);

            if ($purchase < 0.01) {
                $purchase = $unitCost;
            }

            if ($purchase > $unitCost) {
                $purchase = $unitCost;
            }

            return [
                'purchase' => $purchase,
                'unit_cost' => $unitCost,
            ];
        }

        $name = trim((string) $lineName);

        if ($name !== '') {
            $product = Product::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($product && $this->effectiveUnitCost($product) >= 0.01) {
                return [
                    'purchase' => round((float) $product->purchase_price, 2) >= 0.01
                        ? round((float) $product->purchase_price, 2)
                        : $this->effectiveUnitCost($product),
                    'unit_cost' => $this->effectiveUnitCost($product),
                ];
            }
        }

        return null;
    }

    /**
     * When a product has no unit cost, copy purchase/unit cost from order lines
     * (same product_id first, then same line name).
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

        $snapshot = $this->resolveCostSnapshot((int) $product->id, (string) $product->name);

        // Catalog title often differs from short order-line names ("Necklace" vs long SEO title).
        // Try distinct line names used with this product so we can still learn from peers.
        if ($snapshot === null) {
            $lineNames = OrderProduct::query()
                ->where('product_id', $product->id)
                ->whereNotNull('name')
                ->distinct()
                ->orderBy('name')
                ->pluck('name');

            foreach ($lineNames as $lineName) {
                $trimmed = trim((string) $lineName);

                if ($trimmed === '' || strcasecmp($trimmed, trim((string) $product->name)) === 0) {
                    continue;
                }

                $snapshot = $this->resolveCostSnapshot(null, $trimmed);

                if ($snapshot !== null) {
                    break;
                }
            }
        }

        if ($snapshot === null) {
            return false;
        }

        $purchase = $snapshot['purchase'];
        $unitCost = $snapshot['unit_cost'];
        $other = max(0.0, round($unitCost - $purchase, 2));

        $this->applyPurchaseAndOther($product, $purchase, $other);

        return $this->effectiveUnitCost($product->fresh()) >= 0.01;
    }

    /**
     * For each line on the order: fill missing catalog product costs, then fill
     * ৳0 order lines from product / other order snapshots (including by name).
     *
     * @return array{products_updated: int, lines_updated: int}
     */
    public function backfillMissingCostsForOrder(Order $order): array
    {
        $order->loadMissing('items.product.materials');
        $productsUpdated = 0;
        $linesUpdated = 0;
        $seenProducts = [];

        foreach ($order->items as $item) {
            if ($item->product_id && $item->product instanceof Product) {
                $productId = (int) $item->product_id;

                if (! isset($seenProducts[$productId])) {
                    $seenProducts[$productId] = true;

                    if ($this->backfillMissingFromOrderSnapshots($item->product)) {
                        $productsUpdated++;
                        $item->setRelation('product', $item->product->fresh(['materials', 'costHeads']));
                    }
                }
            }
        }

        foreach ($order->items as $item) {
            $kept = max(0, (int) $item->quantity - (int) ($item->returned_quantity ?? 0));

            if ($kept < 1 || $item->effectiveUnitCost() >= 0.01) {
                continue;
            }

            $productId = $item->product_id ? (int) $item->product_id : null;
            $snapshot = $this->resolveCostSnapshot($productId, (string) $item->name);

            if ($snapshot === null) {
                continue;
            }

            $item->forceFill([
                'purchase_price' => $snapshot['purchase'],
                'unit_cost' => $snapshot['unit_cost'],
            ])->save();

            $linesUpdated++;
        }

        return [
            'products_updated' => $productsUpdated,
            'lines_updated' => $linesUpdated,
        ];
    }

    /**
     * For each linked product on the order that still has ৳0 catalog cost, try to
     * fill it from any order-line snapshot for that product or matching name.
     *
     * @return int Number of products updated
     */
    public function backfillMissingProductsFromOrder(Order $order): int
    {
        return $this->backfillMissingCostsForOrder($order)['products_updated'];
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
