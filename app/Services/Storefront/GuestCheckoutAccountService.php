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
    public function provisionGuestAccount(array $customer): ?User
    {
        if (auth()->check()) {
            return auth()->user();
        }

        $user = $this->customers->findOrCreateCustomer(
            $customer['phone'],
            $customer['name'],
            $customer['email'] ?? null,
        );

        if ($user === null || ! $this->canUseStorefrontAccount($user)) {
            return null;
        }

        $this->linkGuestOrders($user);

        return $user;
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

    private function canUseStorefrontAccount(User $user): bool
    {
        return $user->hasRole('customers')
            && ! $user->isStaffAdmin()
            && ! $user->isReseller();
    }
}
