<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records each individual payment event against an order.
 *
 * Successful statuses: 'completed', 'succeeded'
 * Non-counting statuses: 'pending', 'failed', 'voided'
 *
 * order.paid_amount = sum(amount WHERE status IN successful set)
 */
class PaymentTransaction extends Model
{
    protected $guarded = [];

    /** Statuses that count toward orders.paid_amount. */
    public const SUCCESSFUL_STATUSES = ['completed', 'succeeded'];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'meta'       => 'array',
            'paid_at'    => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, self::SUCCESSFUL_STATUSES, true);
    }
}
