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
        // Allow re-send even when a tracker already exists — new tracking replaces the old one.
        return in_array($this->status, ['new', 'confirmed'], true);
    }

    /**
     * Parcel / consignment ID for thermal labels (Steadfast Id), not the tracking code.
     */
    public function printParcelId(): ?string
    {
        if (filled($this->courier_consignment_id)) {
            return (string) $this->courier_consignment_id;
        }

        $fromLogs = $this->consignmentIdFromCourierLogs();

        if ($fromLogs !== null) {
            return $fromLogs;
        }

        return filled($this->courier_tracker) ? (string) $this->courier_tracker : null;
    }

    /**
     * Amount to collect / print as TOTAL DUE.
     *
     * Prefer cod_amount, then due_amount, then total. Compare as floats — Laravel's
     * decimal cast yields "0.00", which is truthy for ?: and would print 0 forever.
     */
    public function collectableAmount(): float
    {
        foreach ([$this->cod_amount, $this->due_amount, $this->total] as $amount) {
            $value = round((float) $amount, 2);

            if ($value > 0) {
                return $value;
            }
        }

        return 0.0;
    }

    public function scopeMatchingPhone(Builder $query, string $phone): Builder
    {
        return $query->whereIn('phone', \App\Support\PhoneNumber::matchCandidates($phone));
    }

    private function consignmentIdFromCourierLogs(): ?string
    {
        $this->loadMissing('courierLogs');

        foreach ($this->courierLogs as $log) {
            $data = is_array($log->api_data) ? $log->api_data : null;

            if ($data === null) {
                continue;
            }

            $id = data_get($data, 'consignment.consignment_id')
                ?? data_get($data, 'consignment.id')
                ?? data_get($data, 'data.consignment.consignment_id')
                ?? data_get($data, 'data.consignment.id')
                ?? data_get($data, 'data.consignment_id')
                ?? data_get($data, 'data.order.consignment_id');

            if (filled($id)) {
                return (string) $id;
            }
        }

        return null;
    }
}
