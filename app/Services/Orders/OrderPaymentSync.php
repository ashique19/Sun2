<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ONLY place that writes orders.paid_amount / due_amount / payment_status / cod_amount.
 *
 * Derives all caches from the payment_transactions ledger.
 * Never deletes or modifies transactions — only reads them.
 *
 * Sync rules (from plan):
 * 1. paid_amount  = sum(amount WHERE status IN successful set)
 * 2. due_amount   = max(0, total - paid_amount)
 * 3. payment_status = unpaid | partial | paid
 * 4. cod_amount   = due_amount (residual intended for courier collection)
 * 5. payment_method (compat summary) = primary method or 'mixed'
 */
class OrderPaymentSync
{
    public function sync(Order $order): void
    {
        $before = [
            'total' => round((float) $order->total, 2),
            'paid_amount' => round((float) ($order->paid_amount ?? 0), 2),
            'due_amount' => round((float) ($order->due_amount ?? 0), 2),
            'cod_amount' => round((float) ($order->cod_amount ?? 0), 2),
            'payment_status' => $order->payment_status,
        ];

        $transactions = $order->paymentTransactions()
            ->whereIn('status', PaymentTransaction::SUCCESSFUL_STATUSES)
            ->get();

        $paidAmount = $transactions->sum(fn ($t) => (float) $t->amount);
        $paidAmount = round($paidAmount, 2);

        $collectedAmount = round(
            $transactions
                ->filter(fn ($t) => strtolower((string) $t->method) === 'cod')
                ->sum(fn ($t) => (float) $t->amount),
            2,
        );

        $total = round((float) $order->total, 2);
        $dueAmount = round(max(0.0, $total - $paidAmount), 2);

        $paymentStatus = match (true) {
            $paidAmount <= 0 => 'unpaid',
            $paidAmount >= $total => 'paid',
            default => 'partial',
        };

        // cod_amount = residual (what the courier should collect)
        $codAmount = $dueAmount;

        // compat payment_method summary
        $paymentMethod = $this->summarizeMethod($transactions);

        $order->paid_amount = $paidAmount;
        $order->due_amount = $dueAmount;
        $order->payment_status = $paymentStatus;
        $order->cod_amount = $codAmount;
        $order->collected_amount = $collectedAmount;

        if ($paymentMethod !== null) {
            $order->payment_method = $paymentMethod;
        }

        $order->save();

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode([
            'id' => 'log_paysync_'.uniqid(),
            'timestamp' => (int) (microtime(true) * 1000),
            'location' => 'OrderPaymentSync.php:sync',
            'message' => 'OrderPaymentSync completed',
            'hypothesisId' => 'B,C,D',
            'data' => [
                'order_id' => $order->id,
                'before' => $before,
                'after' => [
                    'total' => $total,
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'cod_amount' => $codAmount,
                    'payment_status' => $paymentStatus,
                    'collected_amount' => $collectedAmount,
                    'payment_method' => $order->payment_method,
                ],
                'successful_txn_count' => $transactions->count(),
                'successful_txn_amounts' => $transactions->map(fn ($t) => [
                    'id' => $t->id,
                    'method' => $t->method,
                    'amount' => (float) $t->amount,
                    'kind' => $t->kind,
                    'status' => $t->status,
                    'external_id' => $t->external_id,
                ])->values()->all(),
                'zeroed_cod_due_while_total_positive' => $total > 0 && $codAmount <= 0 && $dueAmount <= 0,
                'zeroed_all_from_zero_total' => $total <= 0 && $codAmount <= 0 && $dueAmount <= 0,
            ],
        ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        // #endregion
    }

    /**
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    private function summarizeMethod($transactions): ?string
    {
        if ($transactions->isEmpty()) {
            return null;
        }

        $methods = $transactions->pluck('method')->unique()->values();

        return $methods->count() === 1 ? $methods->first() : 'mixed';
    }
}
