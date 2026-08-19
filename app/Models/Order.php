<?php

namespace App\Models;

use App\Services\Orders\OrderTotalCalculator;
use App\Services\Orders\OrderTotals;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Order extends Model
{
    public const PLACED_VIA_STOREFRONT = 'storefront';

    public const PLACED_VIA_ADMIN = 'admin';

    public const PLACED_VIA_RESELLER = 'reseller';

    public const PLACED_VIA_MESSENGER = 'messenger';

    public const PLACED_VIA_WHATSAPP = 'whatsapp';

    public const STATUS_DRAFT = 'draft';

    /** @var list<string> */
    public const PLACED_VIA_OPTIONS = [
        self::PLACED_VIA_STOREFRONT,
        self::PLACED_VIA_ADMIN,
        self::PLACED_VIA_RESELLER,
        self::PLACED_VIA_MESSENGER,
        self::PLACED_VIA_WHATSAPP,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reseller_id' => 'integer',
            'coupon_id' => 'integer',
            'courier_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'channel_conversation_id' => 'integer',
            'ai_parse_meta' => 'array',
            'subtotal' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'charge' => 'decimal:2',
            'courier_charge' => 'decimal:2',
            'packaging_cost' => 'decimal:2',
            'courier_charge_confirmed_at' => 'datetime',
            'courier_charge_confirmed_by' => 'integer',
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
            'exchange_of_order_id' => 'integer',
            'has_return' => 'boolean',
            'return_hub_arrived_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Who placed the order: named user when known, otherwise channel fallback.
     */
    public function placedByLabel(): string
    {
        $this->loadMissing('createdBy');

        $name = trim((string) ($this->createdBy?->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return match ($this->placed_via) {
            self::PLACED_VIA_ADMIN => 'Admin',
            self::PLACED_VIA_RESELLER => 'Reseller',
            self::PLACED_VIA_MESSENGER => 'Messenger',
            self::PLACED_VIA_WHATSAPP => 'WhatsApp',
            default => 'Customer',
        };
    }

    public function isPlacedByStorefrontCustomer(): bool
    {
        return $this->placed_via === self::PLACED_VIA_STOREFRONT;
    }

    public function isAiDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function channelConversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class);
    }

    /** @deprecated Use placedByLabel() */
    public function createdByLabel(): string
    {
        return $this->placedByLabel();
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

    public function courierChargeConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_charge_confirmed_by');
    }

    public function isCourierChargeConfirmed(): bool
    {
        return $this->courier_charge_confirmed_at !== null;
    }

    public function needsCourierChargeConfirmation(): bool
    {
        return $this->status === 'dispatched' && ! $this->isCourierChargeConfirmed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderProduct::class);
    }

    /** Original order this replacement parcel is exchanging. */
    public function exchangeOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'exchange_of_order_id');
    }

    /** Replacement parcels created for this original order. */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'exchange_of_order_id');
    }

    /**
     * Original + replacements as one commercial event (null when this order is unlinked).
     *
     * @return array{
     *     orders: Collection<int, Order>,
     *     collected: float,
     *     write_off: float,
     *     cogs: float,
     *     packaging: float,
     *     courier: float,
     *     cod: float,
     *     gross_profit: float
     * }|null
     */
    public function exchangePairEconomics(): ?array
    {
        $members = $this->exchangePairOrders();

        if ($members->count() < 2) {
            return null;
        }

        $collected = 0.0;
        $writeOff = 0.0;
        $cogs = 0.0;
        $packaging = 0.0;
        $courier = 0.0;
        $cod = 0.0;
        $grossProfit = 0.0;

        foreach ($members as $member) {
            $member->loadMissing(['items', 'adjustments', 'courier']);
            $totals = $member->moneyTotals();
            $collected += (float) ($member->collected_amount ?? 0);
            $writeOff += (float) $member->adjustments
                ->where('source', 'partial_return_writeoff')
                ->sum('amount');
            $cogs += $totals->cogs;
            $packaging += $totals->packagingCost;
            $courier += $totals->courierCharge;
            $cod += $totals->codCharge;
            $grossProfit += $totals->grossProfit;
        }

        return [
            'orders' => $members,
            'collected' => round($collected, 2),
            'write_off' => round($writeOff, 2),
            'cogs' => round($cogs, 2),
            'packaging' => round($packaging, 2),
            'courier' => round($courier, 2),
            'cod' => round($cod, 2),
            'gross_profit' => round($grossProfit, 2),
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    private function exchangePairOrders(): Collection
    {
        $ids = [];

        if ($this->exchange_of_order_id) {
            $ids[] = (int) $this->exchange_of_order_id;
            $ids[] = (int) $this->id;
            $ids = array_merge(
                $ids,
                Order::query()->where('exchange_of_order_id', $this->exchange_of_order_id)->pluck('id')->all(),
            );
        } else {
            $this->loadMissing('replacements');

            if ($this->replacements->isEmpty()) {
                return collect();
            }

            $ids[] = (int) $this->id;
            $ids = array_merge($ids, $this->replacements->modelKeys());
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) < 2) {
            return collect();
        }

        return Order::query()
            ->with(['items', 'adjustments', 'courier'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
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
     * COGS = sum(unit_cost × effective_quantity) over order lines
     * (falls back to purchase_price on legacy lines).
     * Effective quantity = quantity - returned_quantity when returns apply.
     * Requires items to be loaded.
     */
    public function cogs(): float
    {
        $this->loadMissing('items');

        return app(OrderTotalCalculator::class)->cogsFromItems($this->items);
    }

    /**
     * Net revenue = subtotal - COGS + charges - discounts + delivery_charge - courier_charge - packaging_cost - COD charge.
     * Requires items loaded. Prefer adjustment lines; fall back to order scalars when
     * adjustments are empty (legacy rows / pre-backfill) so admin never shows wrong 0.
     */
    public function netRevenue(): float
    {
        return $this->moneyTotals()->netRevenue;
    }

    /** Delivery margin = customer delivery_charge - courier_charge. */
    public function deliveryMargin(): float
    {
        return $this->moneyTotals()->deliveryMargin;
    }

    /**
     * Courier COD collection fee (1% by default).
     * Steadfast: (collected_amount - delivery_charge) × %; others: collected_amount × %.
     */
    public function codCharge(): float
    {
        return $this->moneyTotals()->codCharge;
    }

    /** Customer invoice / COD bill. */
    public function billToCustomer(): float
    {
        return $this->moneyTotals()->billToCustomer;
    }

    /** Remittance from courier after courier fee and COD %. */
    public function courierReceivable(): float
    {
        return $this->moneyTotals()->courierReceivable;
    }

    /** Gross profit = courier receivable − COGS − packaging. */
    public function grossProfit(): float
    {
        return $this->moneyTotals()->grossProfit;
    }

    public function moneyTotals(): OrderTotals
    {
        $this->loadMissing(['items', 'adjustments', 'courier']);

        $adjustments = $this->adjustments->isNotEmpty()
            ? $this->adjustments
            : collect([
                ['type' => 'charge', 'amount' => (float) $this->charge],
                ['type' => 'discount', 'amount' => (float) $this->discount],
            ])->filter(fn (array $line) => $line['amount'] > 0)->values();

        // Cancelled/returned with no COD collection: remittance base is actual collected (usually 0),
        // not the unpaid bill — so courier receivable correctly becomes −courier_charge.
        if (in_array($this->status, ['cancelled', 'returned'], true)) {
            $expectedCod = max(0.0, (float) ($this->collected_amount ?? 0));
        } else {
            $expectedCod = (float) ($this->cod_amount ?? 0);
            if ($expectedCod <= 0) {
                $expectedCod = (float) ($this->due_amount ?? 0);
            }
            if ($expectedCod <= 0) {
                $expectedCod = (float) $this->total;
            }
        }

        return app(OrderTotalCalculator::class)->calculate(
            subtotal: (float) $this->subtotal,
            deliveryCharge: (float) $this->delivery_charge,
            courierCharge: (float) ($this->courier_charge ?? 0),
            adjustments: $adjustments,
            items: $this->items,
            collectedAmount: (float) ($this->collected_amount ?? 0),
            courierSlug: $this->courier?->slug,
            codPercentage: (float) ($this->courier?->cod_percentage ?? 1),
            packagingCost: (float) ($this->packaging_cost ?? 0),
            expectedCodRemittance: $expectedCod,
        );
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
     * Merchant panel URL for reviewing Steadfast parcel charges.
     * Uses consignment/parcel id only — never the tracking code.
     */
    public function steadfastConsignmentUrl(): ?string
    {
        $this->loadMissing('courier');

        if ($this->courier?->slug !== 'steadfast') {
            return null;
        }

        $parcelId = filled($this->courier_consignment_id)
            ? (string) $this->courier_consignment_id
            : $this->consignmentIdFromCourierLogs();

        if ($parcelId === null || $parcelId === '' || ! ctype_digit($parcelId)) {
            return null;
        }

        return 'https://steadfast.com.bd/user/consignment/'.$parcelId;
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
        return $query->whereIn('phone', PhoneNumber::matchCandidates($phone));
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
