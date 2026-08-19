<?php

namespace App\Services\Admin;

use App\Models\AdminAttentionItem;
use App\Models\Courier;
use App\Models\CourierBalanceEntry;
use App\Models\CourierData;
use App\Models\Order;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\SteadfastApiClient;
use App\Services\Orders\OrderTotalCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierBalanceService
{
    public function __construct(
        private readonly SteadfastApiClient $steadfast,
        private readonly CourierApiRegistry $courierRegistry,
        private readonly OrderTotalCalculator $totals,
    ) {}

    /**
     * Credit book balance when an order is dispatched (COD the courier will hold/owe).
     */
    public function creditOnDispatch(Courier $courier, Order $order, ?int $createdBy = null): void
    {
        $alreadyCredited = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->exists();

        if ($alreadyCredited) {
            return;
        }

        $amount = (int) round($order->collectableAmount());

        if ($amount <= 0) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'dispatch',
            amount: $amount,
            orderId: $order->id,
            note: 'Dispatch #'.$order->order_number,
            createdBy: $createdBy,
        );
    }

    /**
     * Reverse a prior dispatch credit (full cancel & return / no COD collected).
     */
    public function reverseDispatchCredit(Courier $courier, Order $order, ?int $createdBy = null): void
    {
        $dispatch = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->first();

        if (! $dispatch) {
            return;
        }

        $alreadyReversed = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'return')
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $amount = (int) $dispatch->amount;

        if ($amount === 0) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'return',
            amount: -$amount,
            orderId: $order->id,
            note: 'Reverse dispatch #'.$order->order_number.' (C/R)',
            createdBy: $createdBy,
        );
    }

    /**
     * After partial return: reverse original COD credit, then credit what was actually collected.
     */
    public function settleAfterPartialReturn(Courier $courier, Order $order, int $collectedAmount, ?int $createdBy = null): void
    {
        $this->reverseDispatchCredit($courier, $order, $createdBy);

        if ($collectedAmount <= 0) {
            return;
        }

        $alreadySettled = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'dispatch')
            ->where('note', 'like', 'Partial collect%')
            ->exists();

        if ($alreadySettled) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'dispatch',
            amount: $collectedAmount,
            orderId: $order->id,
            note: 'Partial collect #'.$order->order_number,
            createdBy: $createdBy,
        );
    }

    /**
     * Debit book for the courier fee on a cancelled / returned parcel (collected COD = 0).
     * Idempotent per order.
     */
    public function debitCancelFee(Courier $courier, Order $order, int $amount, ?int $createdBy = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $alreadyDebited = CourierBalanceEntry::query()
            ->where('order_id', $order->id)
            ->where('type', 'fee')
            ->exists();

        if ($alreadyDebited) {
            return;
        }

        $this->apply(
            courier: $courier,
            type: 'fee',
            amount: -$amount,
            orderId: $order->id,
            note: 'Courier fee on cancel/return #'.$order->order_number,
            createdBy: $createdBy,
        );
    }

    /**
     * Record a withdrawal / remittance received from the courier.
     */
    public function withdraw(Courier $courier, int $amount, ?string $note = null, ?int $createdBy = null): Courier
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'withdrawAmount' => 'Enter an amount greater than zero.',
            ]);
        }

        $current = (int) round((float) $courier->balance);

        if ($amount > $current) {
            throw ValidationException::withMessages([
                'withdrawAmount' => 'Cannot withdraw more than the book balance (৳'.number_format($current, 0).').',
            ]);
        }

        return $this->apply(
            courier: $courier,
            type: 'withdraw',
            amount: -$amount,
            orderId: null,
            note: $note ?: 'Withdrawal / remittance received',
            createdBy: $createdBy,
        );
    }

    /**
     * Live wallet balance from the courier API, if available.
     *
     * Never throws — API/network failures return null so admin pages stay usable.
     */
    public function fetchApiBalance(Courier $courier): ?float
    {
        $slug = strtolower((string) $courier->slug);

        if ($slug === '' || ! $this->courierRegistry->isConfigured($slug)) {
            return null;
        }

        try {
            return match ($slug) {
                'steadfast' => $this->steadfast->getBalance(),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, float|null> courier_id => api balance
     */
    public function fetchApiBalancesFor(iterable $couriers): array
    {
        $balances = [];

        foreach ($couriers as $courier) {
            $balances[$courier->id] = $this->fetchApiBalance($courier);
        }

        return $balances;
    }

    /**
     * Admin-facing balance breakdown for a courier.
     *
     * - pending: COD still with courier on dispatched parcels (in process)
     * - receivable: net remittance for delivered/cancelled/returned parcels after courier fees
     *   and COD %, minus withdrawals (cancelled with collected 0 contributes −courier_charge)
     * - book: running ledger on couriers.balance
     * - expected_api: same as receivable — cash received − courier charge − COD % − withdrawals
     *   (what the live API wallet should hold; dispatched parcels are not included)
     *
     * Uses OrderTotalCalculator::codCharge() directly — never Order::codCharge()/moneyTotals(),
     * which would eager-load items + adjustments for every delivered order.
     *
     * @return array{pending: float, receivable: float, book: float, withdrawn: float, expected_api: float}
     */
    public function summarize(Courier $courier): array
    {
        return $this->summarizeMany([$courier])[$courier->id];
    }

    /**
     * @param  iterable<Courier>  $couriers
     * @return array<int, array{pending: float, receivable: float, book: float, withdrawn: float, expected_api: float}>
     */
    public function summarizeMany(iterable $couriers): array
    {
        /** @var Collection<int, Courier> $courierList */
        $courierList = collect($couriers)->keyBy('id');
        $ids = $courierList->keys()->all();

        if ($ids === []) {
            return [];
        }

        $pendingByCourier = Order::query()
            ->whereIn('courier_id', $ids)
            ->where('status', 'dispatched')
            ->selectRaw('courier_id, COALESCE(SUM(
                CASE
                    WHEN COALESCE(cod_amount, 0) > 0 THEN cod_amount
                    WHEN COALESCE(due_amount, 0) > 0 THEN due_amount
                    ELSE COALESCE(total, 0)
                END
            ), 0) as pending')
            ->groupBy('courier_id')
            ->pluck('pending', 'courier_id');

        $withdrawnByCourier = CourierBalanceEntry::query()
            ->whereIn('courier_id', $ids)
            ->where('type', 'withdraw')
            ->selectRaw('courier_id, ABS(COALESCE(SUM(amount), 0)) as withdrawn')
            ->groupBy('courier_id')
            ->pluck('withdrawn', 'courier_id');

        $grossByCourier = array_fill_keys($ids, 0.0);

        Order::query()
            ->whereIn('courier_id', $ids)
            ->whereIn('status', ['delivered', 'cancelled', 'returned'])
            ->select(['id', 'courier_id', 'status', 'collected_amount', 'delivery_charge', 'courier_charge'])
            ->orderBy('id')
            ->chunkById(1000, function ($orders) use (&$grossByCourier, $courierList): void {
                foreach ($orders as $order) {
                    $courier = $courierList->get($order->courier_id);

                    if (! $courier) {
                        continue;
                    }

                    $collected = max(0.0, (float) ($order->collected_amount ?? 0));
                    $courierCharge = max(0.0, (float) ($order->courier_charge ?? 0));
                    $codCharge = $this->totals->codCharge(
                        collectedAmount: $collected,
                        deliveryCharge: (float) $order->delivery_charge,
                        courierSlug: $courier->slug,
                        codPercentage: (float) ($courier->cod_percentage ?? 1),
                    );

                    // Allow negative: cancelled/returned with collected 0 still owe courier_charge.
                    $grossByCourier[$order->courier_id] += $collected - $courierCharge - $codCharge;
                }
            });

        $summaries = [];

        foreach ($courierList as $id => $courier) {
            $pending = (float) ($pendingByCourier[$id] ?? 0);
            $withdrawn = (float) ($withdrawnByCourier[$id] ?? 0);
            $grossRemittable = (float) ($grossByCourier[$id] ?? 0);
            $book = round((float) $courier->balance, 2);
            $pendingRounded = round($pending, 2);

            $receivable = round($grossRemittable - $withdrawn, 2);

            $summaries[$id] = [
                'pending' => $pendingRounded,
                'receivable' => $receivable,
                'book' => $book,
                'withdrawn' => round($withdrawn, 2),
                'expected_api' => $receivable,
            ];
        }

        return $summaries;
    }

    /**
     * Orders that help explain an API vs expected (receivable) Diff.
     * Prefers Steadfast webhook `collected_amount` and unresolved COD attention items.
     *
     * @return list<array{
     *     order_id: int,
     *     order_number: string,
     *     customer: string,
     *     status: string,
     *     reason: string,
     *     reason_label: string,
     *     book_expected: float,
     *     courier_collected: float|null,
     *     delta: float,
     *     attention_id: int|null,
     *     tracking_message: string|null
     * }>
     */
    public function mismatchOrders(Courier $courier, int $limit = 50): array
    {
        $rows = [];
        $seen = [];

        $attentionItems = AdminAttentionItem::query()
            ->unresolved()
            ->where('issue_type', AdminAttentionItem::ISSUE_TYPE_COD_MISMATCH)
            ->whereHas('order', fn ($query) => $query->where('courier_id', $courier->id))
            ->with(['order:id,order_number,name,status,cod_amount,due_amount,total,collected_amount,courier_id'])
            ->latest('id')
            ->limit($limit)
            ->get();

        foreach ($attentionItems as $item) {
            $order = $item->order;

            if (! $order) {
                continue;
            }

            $expected = round((float) ($item->data['expected_amount'] ?? $order->collectableAmount()), 2);
            $collected = array_key_exists('collected_amount', $item->data ?? [])
                ? round((float) $item->data['collected_amount'], 2)
                : null;
            $delta = $collected !== null ? round($collected - $expected, 2) : 0.0;

            $rows[] = [
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'customer' => (string) $order->name,
                'status' => (string) $order->status,
                'reason' => 'cod_mismatch',
                'reason_label' => 'COD mismatch (attention)',
                'book_expected' => $expected,
                'courier_collected' => $collected,
                'delta' => $delta,
                'attention_id' => (int) $item->id,
                'tracking_message' => isset($item->data['steadfast_status'])
                    ? (string) $item->data['steadfast_status']
                    : (isset($item->data['reported_status']) ? (string) $item->data['reported_status'] : null),
            ];
            $seen[$order->id] = true;
        }

        $candidateOrders = Order::query()
            ->where('courier_id', $courier->id)
            ->whereIn('status', ['dispatched', 'delivered', 'returned', 'cancelled'])
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'order_number', 'name', 'status', 'cod_amount', 'due_amount', 'total', 'collected_amount']);

        if ($candidateOrders->isNotEmpty()) {
            $orderIds = $candidateOrders->modelKeys();
            $latestCollectedByOrder = $this->latestWebhookCollectedByOrder($orderIds);

            foreach ($candidateOrders as $order) {
                if (isset($seen[$order->id])) {
                    continue;
                }

                $webhook = $latestCollectedByOrder[$order->id] ?? null;

                if ($webhook === null) {
                    continue;
                }

                $expected = $order->status === 'delivered'
                    ? round((float) ($order->collected_amount ?? $order->collectableAmount()), 2)
                    : $order->collectableAmount();
                $collected = $webhook['amount'];
                $delta = round($collected - $expected, 2);

                if (abs($delta) <= 1.0) {
                    continue;
                }

                $rows[] = [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'customer' => (string) $order->name,
                    'status' => (string) $order->status,
                    'reason' => 'webhook_collected_diff',
                    'reason_label' => 'Webhook collected differs',
                    'book_expected' => $expected,
                    'courier_collected' => $collected,
                    'delta' => $delta,
                    'attention_id' => null,
                    'tracking_message' => $webhook['message'],
                ];
                $seen[$order->id] = true;
            }
        }

        $unreversedOrderIds = CourierBalanceEntry::query()
            ->where('courier_id', $courier->id)
            ->where('type', 'dispatch')
            ->whereNotNull('order_id')
            ->whereNotIn('order_id', function ($query) use ($courier) {
                $query->select('order_id')
                    ->from('courier_balance_entries')
                    ->where('courier_id', $courier->id)
                    ->where('type', 'return')
                    ->whereNotNull('order_id');
            })
            ->pluck('amount', 'order_id');

        if ($unreversedOrderIds->isNotEmpty()) {
            $returnedOrders = Order::query()
                ->where('courier_id', $courier->id)
                ->whereIn('status', ['returned', 'cancelled'])
                ->whereIn('id', $unreversedOrderIds->keys())
                ->get(['id', 'order_number', 'name', 'status', 'cod_amount', 'due_amount', 'total']);

            foreach ($returnedOrders as $order) {
                if (isset($seen[$order->id])) {
                    continue;
                }

                $credit = round((float) $unreversedOrderIds[$order->id], 2);

                $rows[] = [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'customer' => (string) $order->name,
                    'status' => (string) $order->status,
                    'reason' => 'unreversed_dispatch',
                    'reason_label' => 'Return without book reverse',
                    'book_expected' => $credit,
                    'courier_collected' => 0.0,
                    'delta' => round(0.0 - $credit, 2),
                    'attention_id' => null,
                    'tracking_message' => 'Dispatch credit still on book after courier return',
                ];
                $seen[$order->id] = true;
            }
        }

        usort($rows, fn (array $a, array $b) => abs($b['delta']) <=> abs($a['delta']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{amount: float, message: string|null}>
     */
    private function latestWebhookCollectedByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $latest = [];

        CourierData::query()
            ->whereIn('order_id', $orderIds)
            ->orderByDesc('id')
            ->get(['id', 'order_id', 'api_data'])
            ->each(function (CourierData $log) use (&$latest): void {
                if (isset($latest[$log->order_id])) {
                    return;
                }

                $data = is_array($log->api_data) ? $log->api_data : [];

                // Prefer explicit collected_amount from Steadfast webhooks.
                if (! array_key_exists('collected_amount', $data) || $data['collected_amount'] === '' || $data['collected_amount'] === null) {
                    return;
                }

                $latest[$log->order_id] = [
                    'amount' => round((float) $data['collected_amount'], 2),
                    'message' => filled($data['tracking_message'] ?? null)
                        ? (string) $data['tracking_message']
                        : (isset($data['status']) ? (string) $data['status'] : null),
                ];
            });

        return $latest;
    }

    private function apply(
        Courier $courier,
        string $type,
        int $amount,
        ?int $orderId,
        ?string $note,
        ?int $createdBy,
    ): Courier {
        return DB::transaction(function () use ($courier, $type, $amount, $orderId, $note, $createdBy) {
            $locked = Courier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $newBalance = (int) round((float) $locked->balance) + $amount;

            $locked->update(['balance' => $newBalance]);

            CourierBalanceEntry::query()->create([
                'courier_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'order_id' => $orderId,
                'note' => $note,
                'created_by' => $createdBy ?? auth()->id(),
            ]);

            return $locked->fresh();
        });
    }
}
