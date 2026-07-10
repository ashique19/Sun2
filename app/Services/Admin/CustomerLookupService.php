<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Couriers\CourierApiRegistry;
use App\Services\Couriers\SteadfastApiClient;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

class CustomerLookupService
{
    public function __construct(
        private SteadfastApiClient $steadfast,
        private CourierApiRegistry $courierRegistry,
        private OrderPasteParser $pasteParser,
    ) {}

    /**
     * @return array{
     *     phone: string|null,
     *     valid: bool,
     *     user: User|null,
     *     last_order: Order|null,
     *     order_count: int,
     *     orders: Collection<int, Order>,
     *     steadfast: array<string, mixed>|null,
     *     steadfast_error: string|null
     * }
     */
    public function lookup(string $rawPhone, ?int $excludeOrderId = null): array
    {
        $phone = PhoneNumber::extractFirstBangladeshMobile($rawPhone);

        if (! $phone || ! PhoneNumber::isValidDisplayMobile($phone)) {
            return [
                'phone' => $phone,
                'valid' => false,
                'user' => null,
                'last_order' => null,
                'order_count' => 0,
                'orders' => collect(),
                'steadfast' => null,
                'steadfast_error' => null,
            ];
        }

        $displayPhone = PhoneNumber::display($phone);

        $ordersQuery = Order::query()
            ->matchingPhone($displayPhone)
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->latest('placed_at')
            ->latest('id');

        $orders = (clone $ordersQuery)->limit(25)->get(['id', 'order_number', 'name', 'phone', 'status', 'total', 'placed_at', 'city', 'area', 'address', 'email']);
        $lastOrder = $orders->first();
        $orderCount = (clone $ordersQuery)->count();

        [$steadfast, $steadfastError] = $this->steadfastStats($displayPhone);

        return [
            'phone' => $displayPhone,
            'valid' => true,
            'user' => User::findByPhone($displayPhone),
            'last_order' => $lastOrder,
            'order_count' => $orderCount,
            'orders' => $orders,
            'steadfast' => $steadfast,
            'steadfast_error' => $steadfastError,
        ];
    }

    /**
     * @return array{name: string, email: string, address: string, cityId: ?int, areaId: ?int, location_hint: ?string}
     */
    public function formDefaultsFromOrder(Order $order): array
    {
        [$cityId, $areaId, $hint] = $this->pasteParser->resolveLocation(
            address: $order->address,
            cityHint: $order->city,
            areaHint: $order->area,
        );

        return [
            'name' => $order->name,
            'email' => (string) ($order->email ?? ''),
            'address' => $order->address,
            'cityId' => $cityId,
            'areaId' => $areaId,
            'location_hint' => $hint,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function steadfastStats(string $phone): array
    {
        if (! $this->courierRegistry->isConfigured('steadfast')) {
            return [null, 'Steadfast API is not configured.'];
        }

        try {
            return [$this->steadfast->fraudCheck($phone), null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }
}
