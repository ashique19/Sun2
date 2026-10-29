<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Couriers\CourierTrackingService;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderCourierStatusController extends Controller
{
    /**
     * Live courier status for a small batch of orders.
     *
     * Runs outside Livewire so a slow Steadfast response cannot freeze the orders list.
     */
    public function __invoke(Request $request, CourierTrackingService $tracking): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:5'],
            'order_ids.*' => ['integer'],
        ]);

        $orderIds = array_values(array_unique(array_map('intval', $validated['order_ids'])));
        $orderIds = array_values(array_filter($orderIds, fn (int $id) => $id > 0));

        $orders = Order::query()
            ->with(['courier:id,name,slug', 'courierLogs'])
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $statuses = [];

        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);

            if (! $order) {
                $statuses[(string) $orderId] = null;

                continue;
            }

            $statuses[(string) $orderId] = $tracking->fetchAndRecordLiveStatus($order, backfillLegacy: false);
        }

        return response()->json([
            'statuses' => $statuses,
        ]);
    }
}
