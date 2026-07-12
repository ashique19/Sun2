<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">Profile</h1>

        <div class="grid lg:grid-cols-4 gap-8 items-start">
            <div class="lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3">
                <form wire:submit="save" class="rounded-xl border border-[#EFE7D6] bg-white p-6 space-y-4 max-w-xl">
                    @if ($statusMessage)
                        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3">{{ $statusMessage }}</div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium mb-1">Full Name</label>
                        <input type="text" wire:model="name"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('name') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Mobile</label>
                        <input type="tel" wire:model="phone" placeholder="01XXXXXXXXX"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('phone') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Email <span class="text-[#8C8474] font-normal">(optional)</span></label>
                        <input type="email" wire:model="email"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                        @error('email') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="border-t border-[#E7DFCF] pt-4">
                        <h2 class="font-medium mb-4">Delivery Address</h2>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Address</label>
                                <textarea wire:model="address" rows="2"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                                @error('address') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid sm:grid-cols-2 gap-4">
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
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Area</label>
                                    <select wire:model="areaId" @disabled(! $cityId)
                                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                                        <option value="">Select area</option>
                                        @foreach ($areas as $area)
                                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('areaId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                        class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                        Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-storefront.shell>
