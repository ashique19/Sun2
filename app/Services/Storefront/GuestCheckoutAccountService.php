<?php

namespace App\Services\Storefront;

use App\Models\Area;
use App\Models\City;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;

class GuestCheckoutAccountService
{
    public function __construct(private CustomerLookupService $customers) {}

    /**
     * @param  array{
     *     name: string,
     *     phone: string,
     *     email?: string|null,
     *     address?: string|null,
     *     area?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     city_id?: int|null,
     *     area_id?: int|null,
     * }  $customer
     */
    public function resolveCheckoutAccount(array $customer): ?User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $this->assertGuestCheckoutAllowed($customer['phone']);

        $user = $this->customers->findOrCreateCustomer(
            $customer['phone'],
            $customer['name'],
            $customer['email'] ?? null,
        );

        if ($user === null) {
            return null;
        }

        $this->syncCheckoutAddress($user, $customer);
        $this->linkGuestOrders($user);

        return $user;
    }

    public function blockedRoleForGuestCheckout(string $phone): ?string
    {
        if (auth()->check()) {
            return null;
        }

        $user = User::findByPhone($phone);

        if ($user === null) {
            return null;
        }

        return $this->restrictedRoleLabel($user);
    }

    public function assertGuestCheckoutAllowed(string $phone): void
    {
        $roleLabel = $this->blockedRoleForGuestCheckout($phone);

        if ($roleLabel !== null) {
            throw new GuestCheckoutStaffPhoneException($roleLabel);
        }
    }

    public function login(User $user): void
    {
        if (auth()->id() === $user->id) {
            return;
        }

        Auth::login($user);
        session()->regenerate();
    }

    public function linkGuestOrders(User $user): int
    {
        if (! PhoneNumber::isValidDisplayMobile((string) $user->phone)) {
            return 0;
        }

        return Order::query()
            ->matchingPhone($user->phone)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    private function restrictedRoleLabel(User $user): ?string
    {
        if ($user->hasRole('admin')) {
            return __('storefront.role_admin');
        }

        if ($user->hasRole('dev')) {
            return __('storefront.role_dev');
        }

        if ($user->hasRole('moderator')) {
            return __('storefront.role_moderator');
        }

        if ($user->hasRole('reseller')) {
            return __('storefront.role_reseller');
        }

        return null;
    }

    /**
     * @param  array{
     *     name: string,
     *     phone: string,
     *     address?: string|null,
     *     area?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     city_id?: int|null,
     *     area_id?: int|null,
     * }  $customer
     */
    private function syncCheckoutAddress(User $user, array $customer): void
    {
        if (! $user->wasRecentlyCreated && $user->addresses()->exists()) {
            return;
        }

        $addressLine = trim((string) ($customer['address'] ?? ''));
        $areaName = trim((string) ($customer['area'] ?? ''));
        $cityName = trim((string) ($customer['city'] ?? ''));

        if ($addressLine === '' && $areaName === '' && $cityName === '') {
            return;
        }

        $cityId = $customer['city_id'] ?? null;
        $areaId = $customer['area_id'] ?? null;

        if ($cityId === null && $cityName !== '') {
            $cityId = City::query()->active()->where('name', $cityName)->value('id');
        }

        if ($areaId === null && $areaName !== '' && $cityId !== null) {
            $areaId = Area::query()
                ->active()
                ->where('city_id', $cityId)
                ->where('name', $areaName)
                ->value('id');
        }

        $city = $cityId !== null ? City::query()->find($cityId) : null;
        $area = $areaId !== null ? Area::query()->find($areaId) : null;

        $defaultAddress = $user->addresses()->where('is_default', true)->first()
            ?? $user->addresses()->make(['is_default' => true]);

        $defaultAddress->fill([
            'name' => $user->name,
            'phone' => PhoneNumber::display((string) $user->phone),
            'address' => $addressLine,
            'city_id' => $city?->id,
            'area_id' => $area?->id,
            'city' => $city?->name ?? ($cityName !== '' ? $cityName : null),
            'area' => $area?->name ?? ($areaName !== '' ? $areaName : null),
            'state' => trim((string) ($customer['state'] ?? '')) ?: ($city?->division ?? $city?->name),
            'is_default' => true,
        ])->save();

        $user->addresses()
            ->where('id', '!=', $defaultAddress->id)
            ->update(['is_default' => false]);
    }
}
