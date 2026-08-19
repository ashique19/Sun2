<?php

namespace App\Services\Storefront;

use App\Models\Order;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;

class GuestCheckoutAccountService
{
    public function __construct(private CustomerLookupService $customers) {}

    /**
     * @param  array{name: string, phone: string, email?: string|null}  $customer
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
}
