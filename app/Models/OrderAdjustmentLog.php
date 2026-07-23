<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log for all order money changes:
 * - adjustment line creates/updates/deletes
 * - delivery_charge edits (field=delivery_charge)
 * - courier_charge phase changes (field=courier_charge)
 */
class OrderAdjustmentLog extends Model
{
    public const UPDATED_AT = null; // append-only; no updated_at

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_before'          => 'decimal:2',
            'amount_after'           => 'decimal:2',
            'order_charge_before'    => 'decimal:2',
            'order_charge_after'     => 'decimal:2',
            'order_discount_before'  => 'decimal:2',
            'order_discount_after'   => 'decimal:2',
            'order_total_before'     => 'decimal:2',
            'order_total_after'      => 'decimal:2',
            'meta_before'            => 'array',
            'meta_after'             => 'array',
            'coupon_id'              => 'integer',
            'order_adjustment_id'    => 'integer',
            'source_courier_data_id' => 'integer',
            'created_at'             => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The adjustment line this log entry refers to.
     * May be null if the line has been deleted or the log covers a non-adjustment field.
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(OrderAdjustment::class, 'order_adjustment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
