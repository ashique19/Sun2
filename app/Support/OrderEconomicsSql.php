<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * SQL expressions matching OrderTotalCalculator COGS / COD rules.
 */
class OrderEconomicsSql
{
    /**
     * Per-order COGS: sum((qty - returned) × COALESCE(unit_cost, purchase_price)).
     * On exchange parcels, lines with price ≤ 0 (free swap units) contribute 0;
     * billed add-on lines keep full merchandise COGS.
     */
    public static function cogsExpression(string $ordersAlias = 'orders'): string
    {
        $qty = self::greatest('(order_products.quantity - COALESCE(order_products.returned_quantity, 0))', '0');
        $lineCost = "{$qty} * COALESCE(order_products.unit_cost, order_products.purchase_price, 0)";

        return "COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE({$ordersAlias}.is_replacement, 0) = 1
                        AND COALESCE(order_products.price, 0) <= 0
                    THEN 0
                    ELSE {$lineCost}
                END
            )
            FROM order_products
            WHERE order_products.order_id = {$ordersAlias}.id
        ), 0)";
    }

    /**
     * Per-order COD fee (Steadfast excludes delivery from the base).
     */
    public static function codExpression(string $ordersAlias = 'orders'): string
    {
        $steadfastBase = self::greatest(
            "(COALESCE({$ordersAlias}.collected_amount, 0) - COALESCE({$ordersAlias}.delivery_charge, 0))",
            '0',
        );

        return "CASE
            WHEN COALESCE({$ordersAlias}.collected_amount, 0) <= 0 THEN 0
            WHEN COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = {$ordersAlias}.courier_id), 1) <= 0 THEN 0
            WHEN LOWER(COALESCE((SELECT couriers.slug FROM couriers WHERE couriers.id = {$ordersAlias}.courier_id), '')) = 'steadfast'
                THEN ROUND({$steadfastBase} * COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = {$ordersAlias}.courier_id), 1) / 100.0, 2)
            ELSE ROUND(COALESCE({$ordersAlias}.collected_amount, 0) * COALESCE((SELECT couriers.cod_percentage FROM couriers WHERE couriers.id = {$ordersAlias}.courier_id), 1) / 100.0, 2)
        END";
    }

    /**
     * Remittance base for delivered (or open) orders: collected, else cod_amount → due → total.
     */
    public static function remittanceBaseExpression(string $ordersAlias = 'orders'): string
    {
        $expected = self::greatest(
            "CASE
                WHEN COALESCE({$ordersAlias}.cod_amount, 0) > 0 THEN {$ordersAlias}.cod_amount
                WHEN COALESCE({$ordersAlias}.due_amount, 0) > 0 THEN {$ordersAlias}.due_amount
                ELSE COALESCE({$ordersAlias}.total, 0)
            END",
            '0',
        );

        return "CASE
            WHEN COALESCE({$ordersAlias}.collected_amount, 0) > 0 THEN {$ordersAlias}.collected_amount
            ELSE {$expected}
        END";
    }

    /**
     * Kept sellable units (ordered minus returned).
     */
    public static function keptQuantityExpression(string $alias = 'order_products'): string
    {
        return self::greatest("({$alias}.quantity - COALESCE({$alias}.returned_quantity, 0))", '0');
    }

    /**
     * Kept merchandise value using unit price (not full line_total when units returned).
     */
    public static function keptValueExpression(string $alias = 'order_products'): string
    {
        return '('.self::keptQuantityExpression($alias)." * COALESCE({$alias}.price, 0))";
    }

    public static function greatest(string $left, string $right): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "max({$left}, {$right})"
            : "GREATEST({$left}, {$right})";
    }
}
