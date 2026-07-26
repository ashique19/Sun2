<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAttentionItem extends Model
{
    public const ISSUE_TYPE_COD_MISMATCH = 'cod_mismatch';

    public const ISSUE_TYPE_ADDRESS_VALIDATION = 'address_validation';

    public const ISSUE_TYPE_PAYMENT_DISCREPANCY = 'payment_discrepancy';

    public const ISSUE_TYPE_SYSTEM_ALERT = 'system_alert';

    public const ISSUE_TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const ISSUE_TYPES = [
        self::ISSUE_TYPE_COD_MISMATCH,
        self::ISSUE_TYPE_ADDRESS_VALIDATION,
        self::ISSUE_TYPE_PAYMENT_DISCREPANCY,
        self::ISSUE_TYPE_SYSTEM_ALERT,
        self::ISSUE_TYPE_OTHER,
    ];

    /**
     * @var list<string>
     */
    public const ISSUE_TYPE_LABELS = [
        self::ISSUE_TYPE_COD_MISMATCH => 'COD Mismatch',
        self::ISSUE_TYPE_ADDRESS_VALIDATION => 'Address Validation',
        self::ISSUE_TYPE_PAYMENT_DISCREPANCY => 'Payment Discrepancy',
        self::ISSUE_TYPE_SYSTEM_ALERT => 'System Alert',
        self::ISSUE_TYPE_OTHER => 'Other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'resolved_by' => 'integer',
            'data' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeByIssueType(Builder $query, string $issueType): Builder
    {
        return $query->where('issue_type', $issueType);
    }

    public function scopeRecentResolved(Builder $query, int $limit = 10): Builder
    {
        return $query->resolved()->latest('resolved_at')->limit($limit);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function getIssueTypeLabel(): string
    {
        return self::ISSUE_TYPE_LABELS[$this->issue_type] ?? $this->issue_type;
    }

    public function markAsResolved(?User $resolvedBy = null, ?string $notes = null): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy?->id,
            'resolution_notes' => $notes,
        ]);
    }
}
