<?php

namespace App\Services\Storefront;

use RuntimeException;

class GuestCheckoutStaffPhoneException extends RuntimeException
{
    public function __construct(public readonly string $roleLabel)
    {
        parent::__construct(__('storefront.checkout_staff_phone_blocked', ['role' => $roleLabel]));
    }
}
