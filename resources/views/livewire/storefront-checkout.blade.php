<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-2">Checkout</h1>
        <p class="text-sm text-[#8C8474] mb-8">Cash on Delivery &middot; OTP confirmation required</p>

        <div class="mb-8 flex items-center gap-3 text-sm">
            <span class="{{ $step === 'details' ? 'font-semibold text-[#C9A227]' : 'text-[#8C8474]' }}">1. Details &amp; Review</span>
            <span class="text-[#D8CDB6]">→</span>
            <span class="{{ $step === 'otp' ? 'font-semibold text-[#C9A227]' : 'text-[#8C8474]' }}">2. OTP Verification</span>
        </div>

        <div class="grid lg:grid-cols-3 gap-8 items-start">
            <div class="lg:col-span-2 space-y-6">
                @if ($step === 'details')
                    <form wire:submit="sendOtp" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                        <h2 class="font-semibold text-lg">Delivery Details</h2>

                        @if ($formError)
                            <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3">{{ $formError }}</div>
                        @endif

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">Full Name</label>
                                <input type="text" wire:model="name"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Mobile (for OTP)</label>
                                <input type="tel" wire:model="phone" placeholder="01XXXXXXXXX"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Email (optional)</label>
                                <input type="email" wire:model="email"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">Delivery Address</label>
                                <textarea wire:model.live.debounce.400ms="address" rows="2"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                                @if ($addressLocationHint)
                                    <p class="text-xs text-emerald-700 mt-1">{{ $addressLocationHint }}</p>
                                @endif
                                @error('address') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">City</label>
                                <select wire:model.live="cityId"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                    <option value="">Select city</option>
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </select>
                                @error('cityId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                @if ($cities->isEmpty())
                                    <p class="text-xs text-amber-700 mt-1">No cities loaded. Run <code>php artisan locations:seed</code>.</p>
                                @endif
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Area</label>
                                <select wire:model.live="areaId" @disabled(! $cityId)
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                                    <option value="">Select area</option>
                                    @foreach ($areas as $area)
                                        <option value="{{ $area->id }}">{{ $area->name }}</option>
                                    @endforeach
                                </select>
                                @error('areaId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">Order Note (optional)</label>
                                <textarea wire:model="customerNote" rows="2"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                            </div>
                        </div>

                        <div class="border-t border-[#E7DFCF] pt-4">
                            <h3 class="font-medium mb-3">Discount Coupon</h3>
                            <div class="flex flex-wrap gap-2">
                                <input type="text" wire:model="couponCode" placeholder="Enter coupon code"
                                    class="flex-1 min-w-[10rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm uppercase focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                <button type="button" wire:click="applyCoupon"
                                    class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                                    Apply
                                </button>
                                @if ($appliedCouponId)
                                    <button type="button" wire:click="removeCoupon" class="text-sm text-[#8C8474] hover:underline">Remove</button>
                                @endif
                            </div>
                            @if ($couponMessage)
                                <p class="text-sm text-emerald-700 mt-2">{{ $couponMessage }}</p>
                            @endif
                            @if ($couponError)
                                <p class="text-sm text-rose-600 mt-2">{{ $couponError }}</p>
                            @endif
                        </div>

                        <button type="submit"
                            class="w-full sm:w-auto rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                            Send OTP &amp; Continue
                        </button>
                    </form>
                @else
                    <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                        <h2 class="font-semibold text-lg mb-2">Verify OTP</h2>
                        <p class="text-sm text-[#6B6459] mb-6">
                            We sent a 6-digit confirmation code to <strong>{{ $phone }}</strong>.
                            @if (app()->hasDebugModeEnabled())
                                <span class="block mt-1 text-xs text-[#8C8474]">Debug mode: use OTP <strong>123456</strong>.</span>
                            @elseif (app()->environment('local'))
                                <span class="block mt-1 text-xs text-[#8C8474]">Local dev: check <code>storage/logs/laravel.log</code> for the OTP.</span>
                            @endif
                        </p>

                        @if ($otpError)
                            <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $otpError }}</div>
                        @endif

                        <form wire:submit="verifyAndPlaceOrder" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Enter OTP</label>
                                <input type="text" wire:model="otp" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                                    class="w-full max-w-xs tracking-[0.4em] text-center text-lg rounded-lg border border-[#E0D6C2] px-4 py-3 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('otp') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button type="submit"
                                    class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                                    Verify &amp; Place Order
                                </button>
                                <button type="button" wire:click="resendOtp"
                                    class="rounded-full border border-[#E0D6C2] px-6 py-3 text-sm hover:bg-[#FAF6EF]">
                                    Resend OTP
                                </button>
                                <button type="button" wire:click="backToDetails" class="text-sm text-[#8C8474] hover:underline">
                                    Edit details
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">Items ({{ $lines->count() }})</h2>
                    <div class="space-y-3 text-sm">
                        @foreach ($lines as $line)
                            <div class="flex justify-between gap-4">
                                <span class="text-[#6B6459] line-clamp-1">{{ $line['product']->name }} &times; {{ $line['quantity'] }}</span>
                                <span class="shrink-0">&#2547; {{ number_format($line['line_total'], 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 h-fit lg:sticky lg:top-24">
                <h2 class="font-semibold mb-4">Order Summary</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-[#6B6459]">Subtotal</span>
                        <span>&#2547; {{ number_format($pricing->subtotal, 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#6B6459]">Delivery charge</span>
                        <span>
                            @if ($pricing->deliveryCharge <= 0)
                                <span class="text-emerald-700">Free</span>
                            @else
                                &#2547; {{ number_format($pricing->deliveryCharge, 0) }}
                            @endif
                        </span>
                    </div>
                    @if ($pricing->discount > 0)
                        <div class="flex justify-between text-emerald-700">
                            <span>Discount</span>
                            <span>− &#2547; {{ number_format($pricing->discount, 0) }}</span>
                        </div>
                    @endif
                </div>
                <div class="border-t border-[#E7DFCF] mt-4 pt-4 flex justify-between font-semibold text-lg">
                    <span>Total (COD)</span>
                    <span>&#2547; {{ number_format($pricing->total, 0) }}</span>
                </div>
                <p class="mt-4 text-xs text-[#8C8474] leading-relaxed">
                    @if ($selectedArea)
                        Delivery for {{ $selectedArea->name }}:
                        &#2547; {{ number_format($selectedArea->delivery_charge_upto_5, 0) }} (up to 5 items),
                        &#2547; {{ number_format($selectedArea->delivery_charge_over_5, 0) }} (more than 5 items).
                        @if ($itemCount > 0)
                            Your cart has {{ $itemCount }} {{ str('item')->plural($itemCount) }}.
                        @endif
                    @elseif ($selectedCity)
                        Select an area to see the exact delivery charge.
                    @else
                        Select a city and area to see delivery charges.
                    @endif
                </p>
                <div class="mt-4 rounded-lg bg-[#FAF6EF] p-3 text-xs text-[#6B6459] leading-relaxed">
                    <p class="font-medium mb-1">Delivery charge guide</p>
                    <p>Dhaka city area: &#2547; 80 (up to 5 items), &#2547; 150 (more than 5 items).</p>
                    <p>Dhaka suburb &amp; outside: &#2547; 120 (up to 5 items), &#2547; 200 (more than 5 items).</p>
                </div>
            </div>
        </div>
    </div>
</x-storefront.shell>
