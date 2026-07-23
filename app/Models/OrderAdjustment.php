<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'sort_order' => 'integer',
            'coupon_id'  => 'integer',
            'meta'       => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The original coupon catalog record. May be null if coupon was deleted. */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isCharge(): bool
    {
        return $this->type === 'charge';
    }

    public function isDiscount(): bool
    {
        return $this->type === 'discount';
    }

    public function isCoupon(): bool
    {
        return $this->type === 'coupon';
    }

    /** Returns a positive amount for charges, negative for discounts/coupons (for sum formulas). */
    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return $this->isCharge() ? $amount : -$amount;
    }
}
