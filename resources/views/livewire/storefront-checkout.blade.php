<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-2">{{ __('storefront.checkout') }}</h1>
        <p class="text-sm text-[#8C8474] mb-8">{{ __('storefront.checkout_subtitle') }}</p>

        <div class="mb-8 flex items-center gap-3 text-sm">
            <span class="{{ $step === 'details' ? 'font-semibold text-[#C9A227]' : 'text-[#8C8474]' }}">{{ __('storefront.step_details') }}</span>
            <span class="text-[#D8CDB6]">→</span>
            <span class="{{ $step === 'otp' ? 'font-semibold text-[#C9A227]' : 'text-[#8C8474]' }}">{{ __('storefront.step_otp') }}</span>
        </div>

        <div class="grid lg:grid-cols-3 gap-8 items-start">
            <div class="lg:col-span-2 space-y-6">
                @if ($step === 'details')
                    <form wire:submit="sendOtp" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4">
                        <h2 class="font-semibold text-lg">{{ __('storefront.delivery_details') }}</h2>

                        @if ($formError)
                            <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3">{{ $formError }}</div>
                        @endif

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.full_name') }}</label>
                                <input type="text" wire:model="name"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.mobile_for_otp') }}</label>
                                <input type="tel" wire:model="phone" placeholder="01XXXXXXXXX"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.email_optional') }}</label>
                                <input type="email" wire:model="email"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.delivery_address') }}</label>
                                <textarea wire:model.live.debounce.400ms="address" rows="2"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                                @if ($addressLocationHint)
                                    <p class="text-xs text-emerald-700 mt-1">{{ $addressLocationHint }}</p>
                                @endif
                                @error('address') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.city') }}</label>
                                <select wire:model.live="cityId"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                    <option value="">{{ __('storefront.select_city') }}</option>
                                    @foreach ($cities as $city)
                                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                                    @endforeach
                                </select>
                                @error('cityId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                @if ($cities->isEmpty())
                                    <p class="text-xs text-amber-700 mt-1">{{ __('storefront.no_cities_hint') }}</p>
                                @endif
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.area') }}</label>
                                <select wire:model.live="areaId" @disabled(! $cityId)
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                                    <option value="">{{ __('storefront.select_area') }}</option>
                                    @foreach ($areas as $area)
                                        <option value="{{ $area->id }}">{{ $area->name }}</option>
                                    @endforeach
                                </select>
                                @error('areaId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.order_note_optional') }}</label>
                                <textarea wire:model="customerNote" rows="2"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.reseller_ref_label') }}</label>
                                <input type="text" wire:model="resellerRef"
                                    placeholder="{{ __('storefront.reseller_ref_placeholder') }}"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('resellerRef') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="border-t border-[#E7DFCF] pt-4">
                            <h3 class="font-medium mb-3">{{ __('storefront.discount_coupon') }}</h3>
                            <div class="flex flex-wrap gap-2">
                                <input type="text" wire:model="couponCode" placeholder="{{ __('storefront.enter_coupon') }}"
                                    class="flex-1 min-w-[10rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm uppercase focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                <button type="button" wire:click="applyCoupon"
                                    class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                                    {{ __('storefront.apply') }}
                                </button>
                            </div>

                            @if ($appliedCouponCodes !== [])
                                <ul class="mt-3 space-y-2 text-sm">
                                    @foreach ($pricing->couponResults as $result)
                                        @if (! $result['rejected'])
                                            <li class="flex items-center justify-between gap-3 rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2">
                                                <div class="min-w-0">
                                                    <p class="font-medium">{{ $result['code'] }}</p>
                                                    <p class="text-emerald-700">− &#2547; {{ number_format($result['amount'], 0) }}</p>
                                                    @if ($result['capped'])
                                                        <p class="text-xs text-amber-700 mt-1">{{ __('storefront.coupon_capped', ['code' => $result['code'], 'amount' => number_format($result['amount'], 0)]) }}</p>
                                                    @endif
                                                </div>
                                                <button type="button" wire:click="removeCoupon('{{ $result['code'] }}')"
                                                    class="shrink-0 text-xs text-[#8C8474] hover:underline">
                                                    {{ __('storefront.coupon_remove') }}
                                                </button>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif

                            @if ($couponMessage)
                                <p class="text-sm text-emerald-700 mt-2">{{ $couponMessage }}</p>
                            @endif
                            @if ($couponError)
                                <p class="text-sm text-rose-600 mt-2">{{ $couponError }}</p>
                            @endif
                        </div>

                        <button type="submit"
                            class="w-full sm:w-auto rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                            {{ __('storefront.send_otp_continue') }}
                        </button>
                    </form>
                @else
                    <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                        <h2 class="font-semibold text-lg mb-2">{{ __('storefront.verify_otp') }}</h2>
                        <p class="text-sm text-[#6B6459] mb-6">
                            {{ __('storefront.otp_sent_to', ['phone' => $phone]) }}
                            @if (app()->hasDebugModeEnabled())
                                <span class="block mt-1 text-xs text-[#8C8474]">Debug: OTP <strong>123456</strong></span>
                            @elseif (app()->environment('local'))
                                <span class="block mt-1 text-xs text-[#8C8474]">Local: check <code>storage/logs/laravel.log</code></span>
                            @endif
                        </p>

                        @if ($otpError)
                            <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $otpError }}</div>
                        @endif

                        <form wire:submit="verifyAndPlaceOrder" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.enter_otp') }}</label>
                                <input type="text" wire:model="otp" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                                    class="w-full max-w-xs tracking-[0.4em] text-center text-lg rounded-lg border border-[#E0D6C2] px-4 py-3 focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('otp') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button type="submit"
                                    class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                                    {{ __('storefront.verify_place_order') }}
                                </button>
                                <button type="button" wire:click="resendOtp"
                                    class="rounded-full border border-[#E0D6C2] px-6 py-3 text-sm hover:bg-[#FAF6EF]">
                                    {{ __('storefront.resend_otp') }}
                                </button>
                                <button type="button" wire:click="backToDetails" class="text-sm text-[#8C8474] hover:underline">
                                    {{ __('storefront.edit_details') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">{{ __('storefront.items', ['count' => $lines->count()]) }}</h2>
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
                <h2 class="font-semibold mb-4">{{ __('storefront.order_summary') }}</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-[#6B6459]">{{ __('storefront.subtotal') }}</span>
                        <span>&#2547; {{ number_format($pricing->subtotal, 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#6B6459]">{{ __('storefront.delivery_charge') }}</span>
                        <span>
                            @if ($pricing->deliveryCharge <= 0)
                                <span class="text-emerald-700">{{ __('storefront.free') }}</span>
                            @else
                                &#2547; {{ number_format($pricing->deliveryCharge, 0) }}
                            @endif
                        </span>
                    </div>
                    @foreach ($pricing->adjustmentLines as $line)
                        <div class="flex justify-between text-emerald-700">
                            <span>
                                @if ($line['type'] === 'coupon')
                                    {{ __('storefront.adjustment_coupon', ['code' => $line['label']]) }}
                                @elseif ($line['type'] === 'discount')
                                    {{ __('storefront.adjustment_discount', ['label' => $line['label']]) }}
                                @else
                                    {{ $line['label'] }}
                                @endif
                            </span>
                            <span>− &#2547; {{ number_format($line['amount'], 0) }}</span>
                        </div>
                    @endforeach
                    @if ($pricing->discount > 0 && $pricing->adjustmentLines === [])
                        <div class="flex justify-between text-emerald-700">
                            <span>{{ __('storefront.discount') }}</span>
                            <span>− &#2547; {{ number_format($pricing->discount, 0) }}</span>
                        </div>
                    @endif
                </div>
                <div class="border-t border-[#E7DFCF] mt-4 pt-4 flex justify-between font-semibold text-lg">
                    <span>{{ __('storefront.total_cod') }}</span>
                    <span>&#2547; {{ number_format($pricing->total, 0) }}</span>
                </div>
                <p class="mt-4 text-xs text-[#8C8474] leading-relaxed">
                    @if ($selectedArea)
                        {{ __('storefront.delivery_for_area', [
                            'area' => $selectedArea->name,
                            'upto5' => number_format($selectedArea->delivery_charge_upto_5, 0),
                            'over5' => number_format($selectedArea->delivery_charge_over_5, 0),
                        ]) }}
                        @if ($itemCount > 0)
                            {{ __('storefront.your_cart_items', ['count' => $itemCount]) }}
                        @endif
                    @elseif ($selectedCity)
                        {{ __('storefront.select_area_for_charge') }}
                    @else
                        {{ __('storefront.select_city_area') }}
                    @endif
                </p>
                @if ($selectedCity || $selectedArea)
                    <div class="mt-4 rounded-lg bg-[#FAF6EF] p-3 text-xs text-[#6B6459] leading-relaxed">
                        <p class="font-medium mb-1">{{ __('storefront.delivery_guide_title') }}</p>
                        <p>{{ __('storefront.delivery_guide_dhaka') }}</p>
                        <p>{{ __('storefront.delivery_guide_outside') }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-storefront.shell>
