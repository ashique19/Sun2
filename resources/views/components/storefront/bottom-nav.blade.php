@php
    $isHome = request()->routeIs('home');
    $isCart = request()->routeIs('cart') || request()->routeIs('checkout*');
@endphp

<nav class="fixed bottom-0 inset-x-0 z-30 sm:hidden border-t border-[#E7DFCF] bg-white"
     style="padding-bottom: env(safe-area-inset-bottom, 0);"
     aria-label="{{ __('storefront.home') }}">
    <div class="grid grid-cols-4 text-[11px] text-[#6B6459]">
        <a href="{{ route('home') }}" wire:navigate
           class="flex flex-col items-center justify-center gap-0.5 py-2 {{ $isHome ? 'text-[#C9A227] font-semibold' : 'hover:text-[#C9A227]' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/>
            </svg>
            <span>{{ __('storefront.home') }}</span>
        </a>
        <a href="{{ route('home') }}#collection" wire:navigate
           class="flex flex-col items-center justify-center gap-0.5 py-2 hover:text-[#C9A227]">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4V5Zm10 0h6v6h-6V5ZM4 13h6v6H4v-6Zm10 0h6v6h-6v-6Z"/>
            </svg>
            <span>{{ __('storefront.categories') }}</span>
        </a>
        <a href="{{ route('cart') }}" wire:navigate
           class="flex flex-col items-center justify-center gap-0.5 py-2 {{ $isCart ? 'text-[#C9A227] font-semibold' : 'hover:text-[#C9A227]' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l1.2 9.2a2 2 0 0 0 2 1.8h8.4a2 2 0 0 0 2-1.7L20 8H7"/>
                <circle cx="10" cy="20" r="1.25" fill="currentColor"/>
                <circle cx="17" cy="20" r="1.25" fill="currentColor"/>
            </svg>
            <span>{{ __('storefront.cart') }}</span>
        </a>
        <a href="tel:01880001255"
           class="flex flex-col items-center justify-center gap-0.5 py-2 hover:text-[#C9A227]">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M6.5 4.5h3l1 4-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2 4 1v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 6.7 2 2 0 0 1 6.5 4.5Z"/>
            </svg>
            <span>{{ __('storefront.call') }}</span>
        </a>
    </div>
</nav>
