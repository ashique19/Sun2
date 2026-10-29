<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Couriers\SteadfastApiClient;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class CustomerLookupService
{
    public function __construct(
        private SteadfastApiClient $steadfast,
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

        return [
            'phone' => $displayPhone,
            'valid' => true,
            'user' => User::findByPhone($displayPhone),
            'last_order' => $lastOrder,
            'order_count' => $orderCount,
            'orders' => $orders,
            'steadfast' => null,
            'steadfast_error' => null,
        ];
    }

    /**
     * Live Steadfast delivery history for a phone. Call this outside Livewire.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function fraudStats(string $phone): array
    {
        $displayPhone = PhoneNumber::display($phone);

        if (! PhoneNumber::isValidDisplayMobile($displayPhone)) {
            return [null, null];
        }

        return $this->steadfastStats($displayPhone);
    }

    /**
     * Find an existing customer by phone, or create one with the customers role.
     * Phone is unique — concurrent creates fall back to a second lookup.
     */
    public function findOrCreateCustomer(string $phone, string $name, ?string $email = null): ?User
    {
        $displayPhone = PhoneNumber::display($phone);

        if (! PhoneNumber::isValidDisplayMobile($displayPhone)) {
            return null;
        }

        $existing = User::findByPhone($displayPhone);

        if ($existing) {
            $this->ensureCustomerRole($existing);

            return $existing;
        }

        Role::findOrCreate('customers');

        $email = filled($email) ? trim((string) $email) : null;
        if ($email !== null && User::query()->where('email', $email)->exists()) {
            $email = null;
        }

        try {
            $user = User::query()->create([
                'name' => trim($name) !== '' ? trim($name) : $displayPhone,
                'phone' => $displayPhone,
                'email' => $email,
                'password' => null,
                'is_active' => true,
            ]);
            $user->assignRole('customers');

            return $user;
        } catch (QueryException) {
            // Unique phone race: another request created the same customer.
            return User::findByPhone($displayPhone);
        }
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

    private function ensureCustomerRole(User $user): void
    {
        if ($user->hasAnyRole(['admin', 'dev', 'moderator', 'reseller'])) {
            return;
        }

        if ($user->hasRole('customers')) {
            return;
        }

        Role::findOrCreate('customers');
        $user->assignRole('customers');
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function steadfastStats(string $phone): array
    {
        if (! $this->steadfast->isFraudCheckAvailable()) {
            return [null, 'Steadfast fraud check is not configured.'];
        }

        try {
            return [$this->steadfast->fraudCheck($phone), null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }
}
