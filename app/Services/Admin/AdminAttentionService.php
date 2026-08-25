<?php

namespace App\Services\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class AdminAttentionService
{
    public function __construct()
    {
        //
    }

    /**
     * Create an admin attention item for COD mismatch / partial delivery review.
     *
     * @param  float|null  $collectedAmount  Null when the courier did not report a reliable collected figure.
     */
    public function createCodMismatch(Order $order, float $expectedAmount, ?float $collectedAmount, array $metadata = []): AdminAttentionItem
    {
        $discrepancy = $collectedAmount === null
            ? null
            : abs($expectedAmount - $collectedAmount);
        $isPartial = (bool) ($metadata['is_partial_delivery'] ?? false);
        $reportedStatus = (string) ($metadata['reported_status'] ?? $metadata['steadfast_status'] ?? '');

        $data = array_merge([
            'expected_amount' => $expectedAmount,
            'collected_amount' => $collectedAmount,
            'discrepancy' => $discrepancy,
            'order_number' => $order->order_number,
            'source' => 'steadfast_webhook',
        ], $metadata);

        $title = $isPartial
            ? "Partial delivery - Order #{$order->order_number}"
            : "COD Mismatch - Order #{$order->order_number}";

        if ($isPartial) {
            $statusSuffix = $reportedStatus !== '' ? " ({$reportedStatus})" : '';
            $description = $collectedAmount === null
                ? "Courier reported partial delivery{$statusSuffix}. COD is ৳{$expectedAmount}; collected amount was not reported — review."
                : "Courier reported partial delivery{$statusSuffix}. COD is ৳{$expectedAmount} but collected ৳{$collectedAmount} — review.";
        } else {
            $description = "COD is ৳{$expectedAmount} but collected ৳{$collectedAmount} at courier";
        }

        $existing = AdminAttentionItem::query()
            ->unresolved()
            ->where('order_id', $order->id)
            ->where('issue_type', AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH)
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->update([
                'title' => $title,
                'description' => $description,
                'data' => $data,
            ]);

            return $existing->refresh();
        }

        return AdminAttentionItem::query()->create([
            'order_id' => $order->id,
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH,
            'title' => $title,
            'description' => $description,
            'data' => $data,
        ]);
    }

    /**
     * Create an admin attention item for address validation issues.
     */
    public function createAddressValidationIssue(Order $order, string $issueDescription, array $metadata = []): AdminAttentionItem
    {
        return AdminAttentionItem::create([
            'order_id' => $order->id,
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_ADDRESS_VALIDATION,
            'title' => "Address Issue - Order #{$order->order_number}",
            'description' => $issueDescription,
            'data' => array_merge([
                'order_number' => $order->order_number,
            ], $metadata),
        ]);
    }

    /**
     * Create an admin attention item for payment discrepancies.
     */
    public function createPaymentDiscrepancy(Order $order, float $expectedAmount, float $actualAmount, string $paymentMethod, array $metadata = []): AdminAttentionItem
    {
        $discrepancy = abs($expectedAmount - $actualAmount);

        return AdminAttentionItem::create([
            'order_id' => $order->id,
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_PAYMENT_DISCREPANCY,
            'title' => "Payment Discrepancy - Order #{$order->order_number}",
            'description' => "Expected payment of ৳{$expectedAmount} via {$paymentMethod}, but received ৳{$actualAmount}",
            'data' => array_merge([
                'expected_amount' => $expectedAmount,
                'actual_amount' => $actualAmount,
                'discrepancy' => $discrepancy,
                'payment_method' => $paymentMethod,
                'order_number' => $order->order_number,
            ], $metadata),
        ]);
    }

    /**
     * Create a generic admin attention item.
     */
    public function createGenericIssue(string $issueType, string $title, string $description, ?Order $order = null, array $data = []): AdminAttentionItem
    {
        if (! in_array($issueType, AdminAttentionItem::ISSUE_TYPES, true)) {
            $issueType = AdminAttentionItem::ISSUE_TYPE_OTHER;
        }

        return AdminAttentionItem::create([
            'order_id' => $order?->id,
            'issue_type' => $issueType,
            'title' => $title,
            'description' => $description,
            'data' => $data,
        ]);
    }

    /**
     * Mark an attention item as resolved.
     */
    public function markAsResolved(AdminAttentionItem $item, ?string $resolutionNotes = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        $item->markAsResolved($user, $resolutionNotes);
    }

    /**
     * Get dashboard summary statistics.
     *
     * @return array{
     *     unresolved_count: int,
     *     recent_resolved: Collection<int, AdminAttentionItem>,
     *     unresolved_by_type: array<string, int>
     * }
     */
    public function getDashboardSummary(): array
    {
        $unresolvedCount = AdminAttentionItem::unresolved()->count();
        $recentResolved = AdminAttentionItem::recentResolved(10)->get();

        $unresolvedByType = [];
        foreach (AdminAttentionItem::ISSUE_TYPES as $type) {
            $unresolvedByType[$type] = AdminAttentionItem::unresolved()->byIssueType($type)->count();
        }

        return [
            'unresolved_count' => $unresolvedCount,
            'recent_resolved' => $recentResolved,
            'unresolved_by_type' => $unresolvedByType,
        ];
    }

    /**
     * Check if a COD amount mismatch exceeds tolerance.
     */
    public function isCodMismatchSignificant(float $expected, float $collected, float $tolerance = 1.0): bool
    {
        return abs($expected - $collected) > $tolerance;
    }

    /**
     * Resolve cash collected from a courier webhook payload.
     *
     * Couriers often send `cod_amount` as the booked consignment COD, not cash
     * actually collected. On partial delivery that value commonly still equals
     * the full order COD — treating it as "collected" misleads admins.
     *
     * Prefer an explicit `collected_amount`. For partial delivery, only treat
     * `cod_amount` (and similar keys) as collected when it differs from the
     * expected COD; otherwise return null (unknown / not reported).
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveCollectedAmountFromPayload(array $payload, float $expectedAmount, bool $isPartial = false): ?float
    {
        if (array_key_exists('collected_amount', $payload)
            && $payload['collected_amount'] !== ''
            && $payload['collected_amount'] !== null) {
            return round((float) $payload['collected_amount'], 2);
        }

        foreach (['cod_amount', 'amount_to_collect', 'collectable_amount'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                continue;
            }

            $amount = round((float) $payload[$key], 2);

            if ($isPartial && ! $this->isCodMismatchSignificant($expectedAmount, $amount)) {
                return null;
            }

            return $amount;
        }

        return $isPartial ? null : round($expectedAmount, 2);
    }
}
