<x-storefront.shell>
    {{-- Hero: bold link back to shop homepage --}}
    <section class="border-b border-[#E7DFCF] bg-gradient-to-br from-[#FAF6EF] via-white to-[#F5EDD8]">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:py-14 text-center">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-[#8F7218] mb-3">
                {{ __('storefront.ads_lab_eyebrow') }}
            </p>
            <a href="{{ route('home') }}" wire:navigate
                class="inline-block font-serif text-3xl sm:text-4xl md:text-5xl font-bold text-[#1E1E1E] hover:text-[#7A6114] transition leading-tight">
                Sundoritoma
            </a>
            <p class="mt-3 text-sm sm:text-base text-[#6B6459] max-w-xl mx-auto">
                {{ __('storefront.ads_lab_hero_subtitle') }}
            </p>
            <a href="{{ route('home') }}" wire:navigate
                class="mt-6 inline-flex items-center justify-center rounded-full bg-[#8F7218] px-6 py-3 text-sm font-semibold text-white hover:bg-[#7A6114] transition">
                {{ __('storefront.ads_lab_back_to_shop') }}
            </a>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:py-12 space-y-10">
        <header class="space-y-2">
            <h1 class="font-serif text-3xl font-semibold text-[#1E1E1E]">
                {{ __('storefront.ads_lab_heading') }}
            </h1>
            <p class="text-sm text-[#6B6459] leading-relaxed max-w-3xl">
                {{ __('storefront.ads_lab_intro', ['network' => strtoupper($network)]) }}
            </p>
            <p class="text-xs text-[#8C8474]">
                {{ __('storefront.ads_lab_private_note') }}
            </p>
        </header>

        <section class="space-y-6">
            <h2 class="text-lg font-semibold text-[#1E1E1E] border-b border-[#EFE7D6] pb-2">
                {{ __('storefront.ads_lab_banner_section') }}
            </h2>

            <div class="grid gap-6 lg:grid-cols-2">
                @foreach ($bannerSlots as $slot)
                    <x-adsterra-slot
                        :slot-key="$slot['slot_key']"
                        :label="$slot['label']"
                        :description="$slot['description']"
                        :width="$slot['width']"
                        :height="$slot['height']"
                        :format="$slot['format']"
                    />
                @endforeach
            </div>
        </section>

        @if (count($scriptSlots) > 0)
            <section class="space-y-6">
                <h2 class="text-lg font-semibold text-[#1E1E1E] border-b border-[#EFE7D6] pb-2">
                    {{ __('storefront.ads_lab_script_section') }}
                </h2>

                @foreach ($scriptSlots as $slot)
                    <div class="rounded-xl border border-[#E0D6C2] bg-white overflow-hidden">
                        <div class="border-b border-[#EFE7D6] bg-[#FAF6EF] px-4 py-3">
                            <p class="text-sm font-semibold text-[#1E1E1E]">{{ $slot['label'] }}</p>
                            @if (filled($slot['description']))
                                <p class="text-xs text-[#6B6459] mt-1">{{ $slot['description'] }}</p>
                            @endif
                        </div>
                        <div class="p-4">
                            @if (filled($slot['body']))
                                <p class="mb-3 text-xs font-medium text-emerald-800">{{ __('storefront.ads_lab_script_loaded') }}</p>
                                {!! $slot['body'] !!}
                            @else
                                <div class="rounded-lg border-2 border-dashed border-[#D9CEB8] bg-[#FAF6EF] px-4 py-8 text-center text-sm text-[#6B6459]">
                                    {{ __('storefront.ads_lab_script_placeholder', ['env' => 'ADSTERRA_SCRIPT_POPUNDER']) }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</x-storefront.shell>
