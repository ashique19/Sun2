<?php

namespace App\Livewire;

use App\Models\Area;
use App\Models\City;
use App\Models\Coupon;
use App\Services\Orders\CouponStackingService;
use App\Services\Reseller\ResellerResolver;
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

    public string $resellerRef = '';

    public string $couponCode = '';

    /** @var list<string> */
    public array $appliedCouponCodes = [];

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

        $this->appliedCouponCodes = $this->couponCodesFromSession();
    }

    public function updatedCityId(): void
    {
        $this->areaId = null;
        $this->couponError = null;
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
        $this->resetErrorBag(['cityId', 'areaId']);
    }

    public function updatedAreaId(): void
    {
        $this->couponError = null;
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
        $this->resetErrorBag('areaId');
    }

    public function updatedAddress(AddressLocationGuesser $guesser): void
    {
        $guess = $guesser->guess($this->address);

        if ($guess) {
            // Keep a manual city/area choice; only auto-fill when the customer has not overridden.
            if (! $this->suppressAddressGuess) {
                $this->cityId = $guess['city_id'];
                $this->areaId = $guess['area_id'];
                $this->addressLocationHint = 'Detected: '.$guess['label'];
            }

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

        $code = strtoupper(trim($this->couponCode));
        if ($code === '') {
            return;
        }

        if (in_array($code, $this->appliedCouponCodes, true)) {
            $this->couponError = __('storefront.coupon_already_applied', ['code' => $code]);
            $this->couponCode = '';

            return;
        }

        $subtotal = $cart->subtotal();
        $error = $coupons->validationMessage($code, $subtotal);
        if ($error) {
            $this->couponError = $error;

            return;
        }

        $coupon = $coupons->findValid($code, $subtotal);
        if (! $coupon) {
            $this->couponError = __('storefront.coupon_invalid');

            return;
        }

        $stacking = app(CouponStackingService::class);
        $existingLines = $this->existingCouponLines($this->appliedCouponCodes);
        $validation = $stacking->validate($coupon, $subtotal, $existingLines);
        if (! $validation['valid']) {
            $this->couponError = $validation['message'];

            return;
        }

        $appliedCoupons = $this->resolveAppliedCoupons($this->appliedCouponCodes);
        $preview = CheckoutPricing::resolveCouponStack(
            [...$appliedCoupons, $coupon],
            $subtotal,
            $cart->lines(),
        );
        $result = collect($preview['results'])->firstWhere('code', $coupon->code);

        if ($result && $result['rejected']) {
            $this->couponError = $result['message'] ?? __('storefront.coupon_rejected', ['code' => $coupon->code]);

            return;
        }

        $this->appliedCouponCodes[] = $code;
        $this->persistCouponCodes();
        $this->couponCode = '';

        if ($result && $result['capped']) {
            $this->couponMessage = __('storefront.coupon_capped', [
                'code' => $coupon->code,
                'amount' => number_format((float) $result['amount'], 0),
            ]);
        } else {
            $this->couponMessage = __('storefront.coupon_applied');
        }
    }

    public function removeCoupon(string $code): void
    {
        $normalized = strtoupper(trim($code));
        $this->appliedCouponCodes = array_values(array_filter(
            $this->appliedCouponCodes,
            fn (string $applied) => strtoupper($applied) !== $normalized,
        ));
        $this->persistCouponCodes();
        $this->couponMessage = __('storefront.coupon_removed', ['code' => $normalized]);
        $this->couponError = null;
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
            'resellerRef' => ['nullable', 'string', 'max:32'],
        ]);

        if (trim($this->resellerRef) !== '') {
            $resolved = app(ResellerResolver::class)->resolve($this->resellerRef);
            if (! $resolved) {
                $this->addError('resellerRef', __('storefront.reseller_not_found'));

                return;
            }
        }

        $area = $this->resolveSelectedArea();
        if (! $area) {
            $this->addError('areaId', __('storefront.invalid_area'));

            return;
        }

        if (! PhoneNumber::isValidBangladeshMobile($this->phone)) {
            $this->addError('phone', __('storefront.invalid_mobile'));

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
            $this->otpError = __('storefront.otp_invalid');

            return;
        }

        $area = $this->resolveSelectedArea();
        if (! $area) {
            $this->otpError = __('storefront.otp_invalid_location');

            return;
        }

        $city = $area->city;
        $appliedCoupons = $this->resolveAppliedCoupons($this->appliedCouponCodes);

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $area,
            $cart->count(),
            $appliedCoupons,
            $cart->lines(),
        );

        $resolvedReseller = trim($this->resellerRef) !== ''
            ? app(ResellerResolver::class)->resolve($this->resellerRef)
            : null;

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
                'reseller_id' => $resolvedReseller?->id,
            ], $appliedCoupons);

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

        $appliedCoupons = $this->resolveAppliedCoupons($this->appliedCouponCodes);
        $itemCount = $cart->count();

        $pricing = CheckoutPricing::calculate(
            $cart->subtotal(),
            $selectedArea ?? $selectedCity,
            $itemCount,
            $appliedCoupons,
            $cart->lines(),
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

    /**
     * Resolve the selected area only when it belongs to the selected city.
     * Cast IDs before comparing — Eloquent may return city_id as a string.
     */
    private function resolveSelectedArea(): ?Area
    {
        if (! $this->areaId || ! $this->cityId) {
            return null;
        }

        $area = Area::query()->with('city')->find($this->areaId);

        if (! $area || (int) $area->city_id !== (int) $this->cityId) {
            return null;
        }

        return $area;
    }

    /** @return list<string> */
    private function couponCodesFromSession(): array
    {
        $codes = session('checkout.coupon_codes');
        if (is_array($codes) && $codes !== []) {
            return array_values(array_unique(array_map(
                fn (string $code) => strtoupper(trim($code)),
                array_filter($codes, fn ($code) => is_string($code) && trim($code) !== ''),
            )));
        }

        $legacy = (string) session('checkout.coupon_code', '');
        if ($legacy !== '') {
            $codes = [strtoupper(trim($legacy))];
            session(['checkout.coupon_codes' => $codes]);
            session()->forget('checkout.coupon_code');

            return $codes;
        }

        return [];
    }

    private function persistCouponCodes(): void
    {
        if ($this->appliedCouponCodes === []) {
            session()->forget('checkout.coupon_codes');
            session()->forget('checkout.coupon_code');

            return;
        }

        session(['checkout.coupon_codes' => $this->appliedCouponCodes]);
        session()->forget('checkout.coupon_code');
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{coupon_id:int}>
     */
    private function existingCouponLines(array $codes): array
    {
        return array_map(
            fn (Coupon $coupon) => ['coupon_id' => $coupon->id],
            $this->resolveAppliedCoupons($codes),
        );
    }

    /**
     * @param  list<string>  $codes
     * @return list<Coupon>
     */
    private function resolveAppliedCoupons(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $normalized = array_map(fn (string $code) => strtoupper(trim($code)), $codes);
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));

        return Coupon::query()
            ->whereRaw('UPPER(code) IN ('.$placeholders.')', $normalized)
            ->get()
            ->sortBy(fn (Coupon $coupon) => array_search(strtoupper($coupon->code), $normalized, true))
            ->values()
            ->all();
    }
}
