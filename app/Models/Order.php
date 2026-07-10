<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'charge' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'cod_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'dispatch_date' => 'datetime',
            'expected_delivery_date' => 'datetime',
            'actual_delivery_date' => 'datetime',
            'payment_date' => 'datetime',
            'is_replacement' => 'boolean',
            'has_return' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('created_at');
    }

    public function courierLogs(): HasMany
    {
        return $this->hasMany(CourierData::class)->latest('created_at');
    }

    public function isDispatchable(): bool
    {
        return in_array($this->status, ['new', 'confirmed'], true) && ! $this->courier_tracker;
    }

    public function scopeMatchingPhone(Builder $query, string $phone): Builder
    {
        return $query->whereIn('phone', \App\Support\PhoneNumber::matchCandidates($phone));
    }
}
