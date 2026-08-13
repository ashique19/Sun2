<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Asia/Dhaka calendar expressions for SQL aggregates.
 *
 * App/DB timestamps are UTC; Bangladesh observes UTC+6 year-round (no DST).
 */
class DhakaSql
{
    /**
     * SQL expression for the Asia/Dhaka calendar date (Y-m-d) of a UTC datetime column.
     */
    public static function date(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '+6 hours')",
            default => "DATE(DATE_ADD({$column}, INTERVAL 6 HOUR))",
        };
    }

    /**
     * SQL expression for the Asia/Dhaka calendar year of a UTC datetime column.
     */
    public static function year(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%Y', {$column}, '+6 hours') AS INTEGER)",
            default => "YEAR(DATE_ADD({$column}, INTERVAL 6 HOUR))",
        };
    }

    /**
     * SQL expression for the Asia/Dhaka calendar month (1–12) of a UTC datetime column.
     */
    public static function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%m', {$column}, '+6 hours') AS INTEGER)",
            default => "MONTH(DATE_ADD({$column}, INTERVAL 6 HOUR))",
        };
    }

    /**
     * SQL expression for Asia/Dhaka year-month key (Y-m).
     */
    public static function yearMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column}, '+6 hours')",
            default => "DATE_FORMAT(DATE_ADD({$column}, INTERVAL 6 HOUR), '%Y-%m')",
        };
    }

    /**
     * Delivered-order value used by dashboard CV (paid, else collected, else total).
     */
    public static function deliveredOrderValueExpression(
        string $paidColumn = 'paid_amount',
        string $collectedColumn = 'collected_amount',
        string $totalColumn = 'total',
    ): string {
        return "CASE
            WHEN COALESCE({$paidColumn}, 0) > 0 THEN {$paidColumn}
            WHEN COALESCE({$collectedColumn}, 0) > 0 THEN {$collectedColumn}
            ELSE COALESCE({$totalColumn}, 0)
        END";
    }
}
