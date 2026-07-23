<?php

namespace App\Models;

use App\Services\Orders\OrderTotalCalculator;
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
            'user_id' => 'integer',
            'reseller_id' => 'integer',
            'coupon_id' => 'integer',
            'courier_id' => 'integer',
            'subtotal'        => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'charge'          => 'decimal:2',
            'courier_charge'  => 'decimal:2',
            'discount'        => 'decimal:2',
            'total'           => 'decimal:2',
            'cod_amount'      => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'paid_amount'     => 'decimal:2',
            'due_amount'      => 'decimal:2',
            'placed_at'              => 'datetime',
            'dispatch_date'          => 'datetime',
            'expected_delivery_date' => 'datetime',
            'actual_delivery_date'   => 'datetime',
            'payment_date'           => 'datetime',
            'is_replacement' => 'boolean',
            'has_return'     => 'boolean',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    /** Primary/legacy coupon. Source of truth is adjustments() for stacked coupons. */
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

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function adjustmentLogs(): HasMany
    {
        return $this->hasMany(OrderAdjustmentLog::class)->latest('created_at');
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->latest('created_at');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('created_at');
    }

    public function courierLogs(): HasMany
    {
        return $this->hasMany(CourierData::class)->latest('created_at');
    }

    // ── Money helpers (delegate to OrderTotalCalculator) ──────────────────────

    /**
     * COGS = sum(purchase_price × effective_quantity) over order lines.
     * Effective quantity = quantity - returned_quantity when returns apply.
     * Requires items to be loaded.
     */
    public function cogs(): float
    {
        $this->loadMissing('items');

        return app(OrderTotalCalculator::class)->cogsFromItems($this->items);
    }

    /**
     * Net revenue = subtotal - COGS + charges - discounts + delivery_charge - courier_charge.
     * Requires items loaded. Prefer adjustment lines; fall back to order scalars when
     * adjustments are empty (legacy rows / pre-backfill) so admin never shows wrong 0.
     */
    public function netRevenue(): float
    {
        $this->loadMissing(['items', 'adjustments']);

        $adjustments = $this->adjustments->isNotEmpty()
            ? $this->adjustments
            : collect([
                ['type' => 'charge', 'amount' => (float) $this->charge],
                ['type' => 'discount', 'amount' => (float) $this->discount],
            ])->filter(fn (array $line) => $line['amount'] > 0)->values();

        return app(OrderTotalCalculator::class)->calculate(
            subtotal: (float) $this->subtotal,
            deliveryCharge: (float) $this->delivery_charge,
            courierCharge: (float) ($this->courier_charge ?? 0),
            adjustments: $adjustments,
            items: $this->items,
        )->netRevenue;
    }

    /** Delivery margin = customer delivery_charge - courier_charge. */
    public function deliveryMargin(): float
    {
        return (float) $this->delivery_charge - (float) ($this->courier_charge ?? 0);
    }

    public function isDispatchable(): bool
    {
        // Allow re-send even when a tracker already exists — new tracking replaces the old one.
        return in_array($this->status, ['new', 'confirmed'], true);
    }

    /**
     * Orders that may be submitted (or re-submitted) to a courier API.
     * Dispatched orders are included so tracking can be replaced without changing status again.
     */
    public function canSendToCourierApi(): bool
    {
        return in_array($this->status, ['new', 'confirmed', 'dispatched'], true);
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
     * Amount the courier should collect.
     *
     * Prefer cod_amount (residual after advances), then due_amount, then total.
     * Compare as floats — Laravel's decimal cast yields "0.00", which is truthy
     * for ?: and would incorrectly fall through.
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
