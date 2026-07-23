<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentTransaction;

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
            $paidAmount <= 0   => 'unpaid',
            $paidAmount >= $total => 'paid',
            default            => 'partial',
        };

        // cod_amount = residual (what the courier should collect)
        $codAmount = $dueAmount;

        // compat payment_method summary
        $paymentMethod = $this->summarizeMethod($transactions);

        $order->paid_amount       = $paidAmount;
        $order->due_amount        = $dueAmount;
        $order->payment_status    = $paymentStatus;
        $order->cod_amount        = $codAmount;
        $order->collected_amount  = $collectedAmount;

        if ($paymentMethod !== null) {
            $order->payment_method = $paymentMethod;
        }

        $order->save();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PaymentTransaction>  $transactions
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
