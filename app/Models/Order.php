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
    public const CUSTOMER_ACTIVE_STATUSES = ['new', 'confirmed', 'dispatched'];

    /** @var list<string> */
    public const CUSTOMER_TRACKING_STATUSES = ['dispatched', 'delivered', 'returned'];

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
     * Original + replacements as independent commercial events (null when unlinked).
     * Each member keeps its own sale economics; the pair is their sum.
     *
     * @return array{
     *     orders: Collection<int, Order>,
     *     collected: float,
     *     write_off: float,
     *     cogs: float,
     *     packaging: float,
     *     courier: float,
     *     cod: float,
     *     gross_profit: float,
     *     net_revenue: float
     * }|null
     */
    public function exchangePairEconomics(): ?array
    {
        $members = $this->exchangePairOrders();

        if ($members->count() < 2) {
            return null;
        }

        $collected = 0.0;
        $cogs = 0.0;
        $packaging = 0.0;
        $courier = 0.0;
        $cod = 0.0;
        $grossProfit = 0.0;
        $netRevenue = 0.0;

        foreach ($members as $member) {
            $member->loadMissing(['items', 'adjustments', 'courier']);
            $totals = $member->moneyTotals();
            $collected += (float) ($member->collected_amount ?? 0);
            $cogs += $totals->cogs;
            $packaging += $totals->packagingCost;
            $courier += $totals->courierCharge;
            $cod += $totals->codCharge;
            $grossProfit += $totals->grossProfit;
            $netRevenue += $totals->netRevenue;
        }

        return [
            'orders' => $members,
            'collected' => round($collected, 2),
            // Legacy key kept for callers; exchange linking no longer applies write-offs.
            'write_off' => 0.0,
            'cogs' => round($cogs, 2),
            'packaging' => round($packaging, 2),
            'courier' => round($courier, 2),
            'cod' => round($cod, 2),
            'gross_profit' => round($grossProfit, 2),
            'net_revenue' => round($netRevenue, 2),
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
     * Exchange parcels are independent: free swap lines (price ≤ 0) do not add
     * merchandise COGS; billed add-on lines do. Charges/discounts use normal math.
     * Requires items to be loaded.
     */
    public function cogs(): float
    {
        $this->loadMissing('items');

        return app(OrderTotalCalculator::class)->cogsFromItems(
            $this->itemsForMerchandiseCogs(),
        );
    }

    /**
     * Free replacement parcel with no billed merchandise (subtotal ≤ 0).
     */
    public function isFreeExchangeReplacement(): bool
    {
        if (! (bool) $this->is_replacement) {
            return false;
        }

        return round((float) $this->subtotal, 2) <= 0.0;
    }

    /**
     * Lines used for merchandise COGS. On exchange parcels, warranty/swap units
     * priced at 0 are excluded so add-on products keep independent P/L.
     *
     * @return Collection<int, OrderProduct|array{unit_cost: float|int, purchase_price?: float|int, quantity: int, returned_quantity: int}>
     */
    public function itemsForMerchandiseCogs()
    {
        $this->loadMissing('items');

        if (! (bool) $this->is_replacement) {
            return $this->items;
        }

        return $this->items->map(function ($item) {
            $billed = round((float) $item->price, 2) > 0.0;

            return [
                'unit_cost' => $billed ? $item->effectiveUnitCost() : 0,
                'purchase_price' => $billed ? (float) ($item->purchase_price ?? 0) : 0,
                'quantity' => (int) $item->quantity,
                'returned_quantity' => (int) ($item->returned_quantity ?? 0),
            ];
        });
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
            $expectedCod = $this->collectableAmount();
        }

        return app(OrderTotalCalculator::class)->calculate(
            subtotal: (float) $this->subtotal,
            deliveryCharge: (float) $this->delivery_charge,
            courierCharge: (float) ($this->courier_charge ?? 0),
            adjustments: $adjustments,
            items: $this->itemsForMerchandiseCogs(),
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
     * Amount the courier should collect (cash on delivery).
     *
     * When any successful payment is on the order (advance / partial / settlement
     * caches on paid_amount), residual is always total − paid. Never fall back to
     * the full bill after advances — that previously sent the initial amount to
     * couriers even when due/cod were correctly zeroed by OrderPaymentSync.
     *
     * Legacy unpaid rows with empty cod/due still fall back to total (print labels).
     * Compare as floats — Laravel's decimal cast yields "0.00", which is truthy
     * for ?: and would incorrectly fall through.
     */
    public function collectableAmount(): float
    {
        $total = round((float) $this->total, 2);
        $paid = round((float) ($this->paid_amount ?? 0), 2);
        $cod = round((float) ($this->cod_amount ?? 0), 2);
        $due = round((float) ($this->due_amount ?? 0), 2);
        $result = 0.0;
        $branch = 'zero_fallback';

        if ($paid > 0) {
            $result = round(max(0.0, $total - $paid), 2);
            $branch = 'paid_residual';
        } else {
            foreach ([$cod, $due, $total] as $idx => $value) {
                if ($value > 0) {
                    $result = $value;
                    $branch = ['cod_amount', 'due_amount', 'total'][$idx];
                    break;
                }
            }
        }

        // #region agent log
        $txnCount = null;
        try {
            $txnCount = $this->relationLoaded('paymentTransactions')
                ? $this->paymentTransactions->count()
                : $this->paymentTransactions()->count();
        } catch (\Throwable) {
            $txnCount = -1;
        }
        $bill = null;
        try {
            // Avoid recursion via moneyTotals()->collectableAmount: compute bill from scalars only.
            $charges = (float) ($this->charge ?? 0);
            $discounts = (float) ($this->discount ?? 0);
            if ($this->relationLoaded('adjustments') && $this->adjustments->isNotEmpty()) {
                $charges = (float) $this->adjustments->where('type', 'charge')->sum('amount');
                $discounts = (float) $this->adjustments->whereIn('type', ['discount', 'coupon'])->sum('amount');
            }
            $bill = round(max(0.0, (float) $this->subtotal + (float) $this->delivery_charge + $charges - $discounts), 2);
        } catch (\Throwable) {
            $bill = -1.0;
        }
        file_put_contents('/opt/cursor/logs/debug.log', json_encode([
            'id' => 'log_collectable_'.uniqid(),
            'timestamp' => (int) (microtime(true) * 1000),
            'location' => 'Order.php:collectableAmount',
            'message' => 'collectableAmount evaluated',
            'hypothesisId' => 'A,B,C',
            'data' => [
                'order_id' => $this->id,
                'total' => $total,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'cod_amount' => $cod,
                'payment_status' => $this->payment_status,
                'status' => $this->status,
                'placed_via' => $this->placed_via,
                'subtotal' => (float) $this->subtotal,
                'delivery_charge' => (float) $this->delivery_charge,
                'billApprox' => $bill,
                'bill_vs_total_diverged' => $bill !== null && $bill >= 0 && abs($bill - $total) >= 0.01,
                'collectable' => $result,
                'branch' => $branch,
                'payment_txn_count' => $txnCount,
            ],
        ], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        // #endregion

        return $result;
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

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeActiveForCustomer(Builder $query): Builder
    {
        return $query->whereIn('status', self::CUSTOMER_ACTIVE_STATUSES);
    }

    public function isActiveForCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_ACTIVE_STATUSES, true);
    }

    public function showsCustomerCourierTracking(): bool
    {
        if (! in_array($this->status, self::CUSTOMER_TRACKING_STATUSES, true)) {
            return false;
        }

        if (filled($this->courier_tracker)) {
            return true;
        }

        $this->loadMissing('courierLogs');

        return $this->courierLogs->isNotEmpty();
    }

    public function customerStatusLabel(): string
    {
        return self::customerStatusLabelFor((string) $this->status);
    }

    public static function customerStatusLabelFor(string $status): string
    {
        $key = 'storefront.order_status_'.$status;
        $label = __($key);

        return $label !== $key ? $label : ucfirst($status);
    }
}
