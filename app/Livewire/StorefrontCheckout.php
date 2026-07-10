<?php

namespace App\Livewire;

use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;
use App\Services\Storefront\AddressLocationGuesser;
use App\Services\Storefront\CartService;
use App\Services\Storefront\CheckoutOtpService;
use App\Services\Storefront\CheckoutPricing;
use App\Services\Storefront\CouponService;
use App\Services\Storefront\OrderPlacer;
use App\Support\PhoneNumber;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Checkout - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontCheckout extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public ?int $cityId = null;

    public ?int $areaId = null;

    public string $customerNote = '';

    public string $couponCode = '';

    public ?int $appliedCouponId = null;

    public ?string $couponMessage = null;

    public ?string $couponError = null;

    public string $step = 'details';

    public string $otp = '';

    public ?string $otpError = null;

    public ?string $formError = null;

    public ?string $addressLocationHint = null;

    public bool $suppressAddressGuess = false;

    public function mount(CartService $cart): void
    {
        if ($cart->lines()->isEmpty()) {
            $this->redirect(route('cart'), navigate: true);
        }

        if (auth()->check()) {
            $user = auth()->user();
            $this->name = $user->name;
            $this->phone = $user->phone;
            $this->email = (string) ($user->email ?? '');

            $defaultAddress = $user->addresses()->where('is_default', true)->first()
                ?? $user->addresses()->latest()->first();

            if ($defaultAddress) {
                $this->address = (string) $defaultAddress->address;
                $this->cityId = $defaultAddress->city_id;
                $this->areaId = $defaultAddress->area_id;

                if (! $this->cityId && $defaultAddress->city) {
                    $this->cityId = City::query()
                        ->active()
                        ->where('name', $defaultAddress->city)
                        ->value('id');
                }

                if (! $this->areaId && $defaultAddress->area && $this->cityId) {
                    $this->areaId = Area::query()
                        ->active()
                        ->where('city_id', $this->cityId)
                        ->where('name', $defaultAddress->area)
                        ->value('id');
                }
            }
        }

        if (! $this->cityId) {
            $this->cityId = City::query()
                ->active()
                ->where('is_dhaka', true)
                ->orderBy('name')
                ->value('id');
        }

        $this->couponCode = (string) session('checkout.coupon_code', '');
        if ($this->couponCode !== '') {
            $this->applyCoupon($cart, app(CouponService::class));
        }
    }

    public function updatedCityId(): void
    {
        $this->areaId = null;
        $this->couponError = null;
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
    }

    public function updatedAreaId(): void
    {
        $this->couponError = null;
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
    }

    public function updatedAddress(AddressLocationGuesser $guesser): void
    {
        $guess = $guesser->guess($this->address);

        if ($guess) {
            $this->cityId = $guess['city_id'];
            $this->areaId = $guess['area_id'];
            $this->addressLocationHint = 'Detected: '.$guess['label'];
            $this->suppressAddressGuess = false;

            return;
        }

        if (! $this->suppressAddressGuess) {
            $this->addressLocationHint = null;
        }
    }

    public function applyCoupon(CartService $cart, CouponService $coupons): void
    {
        $this->couponError = null;
        $this->couponMessage = null;
        $this->appliedCouponId = null;

        if (trim($this->couponCode) === '') {
            session()->forget('checkout.coupon_code');

            return;
        }

        $subtotal = $cart->subtotal();
        $error = $coupons->validationMessage($this->couponCode, $subtotal);

        if ($error) {
            $this->couponError = $error;

            return;
        }

        $coupon = $coupons->findValid($this->couponCode, $subtotal);
        $this->appliedCouponId = $coupon?->id;
        $this->couponMessage = 'Coupon applied successfully.';
        session(['checkout.coupon_code' => strtoupper(trim($this->couponCode))]);
    }

    public function removeCoupon(): void
    {
        $this->couponCode = '';
        $this->appliedCouponId = null;
        $this->couponMessage = null;
        $this->couponError = null;
        session()->forget('checkout.coupon_code');
    }

    public function sendOtp(CartService $cart, CouponService $coupons, CheckoutOtpService $otpService): void
    {
        $this->formError = null;
        $this->otpError = null;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'areaId' => ['required', 'integer', 'exists:areas,id'],
            'email' => ['nullable', 'email', 'max:120'],
            'customerNote' => ['nullable', 'string', 'max:500'],
        ]);

        $area = Area::query()->with('city')->find($this->areaId);
        if (! $area || $area->city_id !== $this->cityId) {
            $this->addError('areaId', 'Please select a valid area for the chosen city.');

            return;
        }

        if (! PhoneNumber::isValidBangladeshMobile($this->phone)) {
            $this->addError('phone', 'Enter a valid Bangladesh mobile number.');

            return;
        }

        try {
            $otpService->send($this->phone);
            $this->step = 'otp';
            $this->otp = '';
        } catch (\Throwable $e) {
            $this->formError = $e->getMessage();
        }
    }

    public function resendOtp(CheckoutOtpService $otpService): void
    {
        $this->otpError = null;

        try {
            $otpService->send($this->phone);
            $this->dispatch('otp-resent');
        } catch (\Throwable $e) {
            $this->otpError = $e->getMessage();
        }
    }

    public function verifyAndPlaceOrder(
        CartService $cart,
        CouponService $coupons,
        CheckoutOtpService $otpService,
        OrderPlacer $placer,
    ): void {
        $this->otpError = null;

        $this->validate([
            'otp' => ['required', 'string', 'size:6'],
        ]);

        if (! $otpService->verify($this->phone, $this->otp)) {
            $this->otpError = 'Invalid or expired OTP. Please try again or resend a new code.';

            return;
        }

        $area = Area::query()->with('city')->find($this->areaId);
        if (! $area || $area->city_id !== $this->cityId) {
            $this->otpError = 'Invalid city or area selection. Please go back and update your delivery details.';

            return;
        }

        $city = $area->city;
        $coupon = $this->appliedCouponId
            ? Coupon::query()->find($this->appliedCouponId)
            : null;

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            $coupon,
        );

        try {
            $order = $placer->place($cart, $pricing, [
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
                'area' => $area->name,
                'city' => $city->name,
                'state' => $city->division ?? $city->name,
                'customer_note' => $this->customerNote,
            ], $coupon);

            $this->redirect(route('checkout.confirmation', $order), navigate: true);
        } catch (\Throwable $e) {
            $this->otpError = $e->getMessage();
        }
    }

    public function backToDetails(): void
    {
        $this->step = 'details';
        $this->otp = '';
        $this->otpError = null;
    }

    public function render(CartService $cart, CouponService $coupons)
    {
        $cities = City::query()->active()->orderBy('name')->get(['id', 'name', 'is_dhaka']);

        $areas = $this->cityId
            ? Area::query()->active()->where('city_id', $this->cityId)->orderBy('name')->get(['id', 'name'])
            : collect();

        $selectedArea = $this->areaId
            ? Area::query()->find($this->areaId)
            : null;

        $selectedCity = $this->cityId
            ? $cities->firstWhere('id', $this->cityId)
            : null;

        $coupon = $this->appliedCouponId
            ? Coupon::query()->find($this->appliedCouponId)
            : null;

        $itemCount = $cart->count();

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $selectedArea ?? $selectedCity,
            $itemCount,
            $coupon,
        );

        return view('livewire.storefront-checkout', [
            'lines' => $cart->lines(),
            'pricing' => $pricing,
            'cities' => $cities,
            'areas' => $areas,
            'selectedCity' => $selectedCity,
            'selectedArea' => $selectedArea,
            'itemCount' => $itemCount,
        ]);
    }
}
