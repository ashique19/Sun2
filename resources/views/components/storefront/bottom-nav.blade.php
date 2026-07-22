@php
    $isHome = request()->routeIs('home');
    $isCart = request()->routeIs('cart') || request()->routeIs('checkout*');
    $isWishlist = request()->routeIs('account.wishlist');
    $whatsappUrl = config('seo.whatsapp_url');
    $wishlistCount = auth()->check()
        ? app(\App\Services\Storefront\WishlistService::class)->count(auth()->id())
        : 0;
@endphp

<nav class="storefront-bottom-nav sm:hidden" aria-label="{{ __('storefront.menu') }}">
    <div class="storefront-bottom-nav__items">
        <a href="{{ route('home') }}" wire:navigate
           class="storefront-bottom-nav__link {{ $isHome ? 'is-active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/>
            </svg>
            <span>{{ __('storefront.home') }}</span>
        </a>
        <a href="{{ route('home') }}#collection" wire:navigate
           class="storefront-bottom-nav__link">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4V5Zm10 0h6v6h-6V5ZM4 13h6v6H4v-6Zm10 0h6v6h-6v-6Z"/>
            </svg>
            <span>{{ __('storefront.categories') }}</span>
        </a>
        <a href="{{ route('cart') }}" wire:navigate
           class="storefront-bottom-nav__link {{ $isCart ? 'is-active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l1.2 9.2a2 2 0 0 0 2 1.8h8.4a2 2 0 0 0 2-1.7L20 8H7"/>
                <circle cx="10" cy="20" r="1.25" fill="currentColor"/>
                <circle cx="17" cy="20" r="1.25" fill="currentColor"/>
            </svg>
            <span>{{ __('storefront.cart') }}</span>
        </a>
        <a href="{{ route('account.wishlist') }}" wire:navigate
           class="storefront-bottom-nav__link {{ $isWishlist ? 'is-active' : '' }}">
            <span class="relative inline-flex">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7-4.35-7-9.2A3.8 3.8 0 0 1 12 7.5a3.8 3.8 0 0 1 7 3.3C19 15.65 12 20 12 20Z"/>
                </svg>
                @if ($wishlistCount > 0)
                    <span class="absolute -top-1.5 -right-2 min-w-[1rem] h-[1rem] rounded-full bg-[#C9A227] text-white text-[9px] font-semibold flex items-center justify-center px-0.5">
                        {{ $wishlistCount > 99 ? '99+' : $wishlistCount }}
                    </span>
                @endif
            </span>
            <span>{{ __('storefront.save') }}</span>
        </a>
        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer"
           class="storefront-bottom-nav__link">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12.04 2C6.58 2 2.15 6.41 2.15 11.84c0 1.94.57 3.75 1.56 5.28L2 22l5.05-1.65a9.86 9.86 0 0 0 4.99 1.34h.01c5.46 0 9.89-4.41 9.89-9.85C21.94 6.41 17.5 2 12.04 2Zm0 17.95h-.01a8.1 8.1 0 0 1-4.12-1.13l-.3-.18-3 .98 1.01-2.92-.19-.31a8.05 8.05 0 0 1-1.24-4.3c0-4.47 3.66-8.1 8.16-8.1 4.5 0 8.16 3.63 8.16 8.1 0 4.47-3.66 8.1-8.17 8.1Zm4.47-6.05c-.24-.12-1.45-.71-1.67-.79-.22-.08-.39-.12-.55.12-.16.24-.63.79-.78.95-.14.16-.29.18-.53.06-.24-.12-1.02-.37-1.95-1.19-.72-.64-1.21-1.42-1.35-1.66-.14-.24-.02-.37.11-.49.11-.11.24-.29.37-.43.12-.14.16-.24.24-.41.08-.16.04-.31-.02-.43-.06-.12-.55-1.32-.75-1.81-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.43.06-.65.31-.22.24-.86.84-.86 2.04s.88 2.36 1 2.53c.12.16 1.74 2.65 4.21 3.72 2.47 1.06 2.47.71 2.91.67.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28Z"/>
            </svg>
            <span>{{ __('storefront.whatsapp') }}</span>
        </a>
    </div>
</nav>
