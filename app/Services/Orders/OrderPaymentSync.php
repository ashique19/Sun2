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
 *
 * COD settlements from courier webhooks only count once delivery is recognized
 * (status delivered/returned/cancelled, or actual_delivery_date set). Otherwise a
 * premature COD ledger row zeros Amount to collect while the parcel is still out
 * and Steadfast still holds the full COD — classic COD mismatch.
 *
 * When orders.total is stale at ~0 but the reconstructed invoice bill is positive,
 * total is healed to that bill before deriving due/cod (unpaid COD merchandise).
 */
class OrderPaymentSync
{
    public function sync(Order $order): void
    {
        $transactions = $order->paymentTransactions()
            ->whereIn('status', PaymentTransaction::SUCCESSFUL_STATUSES)
            ->get();

        $paidAmount = round(
            $transactions
                ->filter(fn (PaymentTransaction $t) => $this->countsTowardPaid($order, $t))
                ->sum(fn (PaymentTransaction $t) => (float) $t->amount),
            2,
        );

        $collectedAmount = round(
            $transactions
                ->filter(fn (PaymentTransaction $t) => $this->countsTowardCourierCollected($order, $t))
                ->sum(fn (PaymentTransaction $t) => (float) $t->amount),
            2,
        );

        $total = round((float) $order->total, 2);

        // Heal stale zero total from merchandise bill before deriving due/cod.
        if ($total <= 0) {
            $invoiceBill = $order->reconstructedInvoiceBill();
            if ($invoiceBill > 0) {
                $order->total = $invoiceBill;
                $total = $invoiceBill;
            }
        }

        $dueAmount = round(max(0.0, $total - $paidAmount), 2);

        $paymentStatus = match (true) {
            $paidAmount <= 0 => 'unpaid',
            $paidAmount >= $total => 'paid',
            default => 'partial',
        };

        // cod_amount = residual (what the courier should collect)
        $codAmount = $dueAmount;

        // compat payment_method summary — all successful txs (including pending COD settlements)
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
    }

    /**
     * Non-COD payments and COD advances always reduce collectable.
     * Courier COD settlements wait until delivery is recognized.
     */
    public function countsTowardPaid(Order $order, PaymentTransaction $transaction): bool
    {
        if (strtolower((string) $transaction->method) !== 'cod') {
            return true;
        }

        $kind = strtolower((string) ($transaction->kind ?? 'settlement'));

        if (in_array($kind, ['advance', 'partial'], true)) {
            return true;
        }

        return $this->codSettlementIsRecognized($order, $transaction);
    }

    /**
     * Courier-collected cash: COD settlements only (not advances), once recognized.
     */
    public function countsTowardCourierCollected(Order $order, PaymentTransaction $transaction): bool
    {
        if (strtolower((string) $transaction->method) !== 'cod') {
            return false;
        }

        $kind = strtolower((string) ($transaction->kind ?? 'settlement'));

        if ($kind === 'advance') {
            return false;
        }

        return $this->codSettlementIsRecognized($order, $transaction);
    }

    private function codSettlementIsRecognized(Order $order, PaymentTransaction $transaction): bool
    {
        // Staff-recorded COD (shop / manual) counts immediately.
        if ($transaction->received_by !== null) {
            return true;
        }

        if ($order->actual_delivery_date !== null) {
            return true;
        }

        return in_array($order->status, ['delivered', 'returned', 'cancelled'], true);
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
