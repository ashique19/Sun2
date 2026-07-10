@props(['query' => ''])

@php
    $cartCount = app(\App\Services\Storefront\CartService::class)->count();
    $wishlistCount = auth()->check()
        ? app(\App\Services\Storefront\WishlistService::class)->count(auth()->id())
        : 0;
@endphp

<header class="border-b border-[#E7DFCF] bg-[#FAF6EF] sticky top-0 z-20 sm:bg-[#FAF6EF]/90 sm:backdrop-blur">
    {{-- Desktop / tablet --}}
    <div class="mx-auto max-w-6xl px-4 py-3 sm:py-4 hidden sm:flex items-center gap-3 sm:gap-4">
        <a href="{{ route('home') }}" wire:navigate class="flex items-center shrink-0 min-w-[9.5rem] sm:min-w-[12rem] md:min-w-[14rem]" aria-label="Sundoritoma home">
            <img src="/img/settings/logo.png"
                 alt="Sundoritoma"
                 class="h-14 sm:h-16 md:h-[4.75rem] w-auto max-w-[12rem] sm:max-w-[16rem] md:max-w-[18rem] object-contain object-left">
        </a>

        <form action="{{ route('search') }}" method="get" class="flex-1 flex justify-center min-w-0 max-w-sm sm:max-w-md">
            <input type="search" name="q" value="{{ $query }}"
                placeholder="Search name, SKU or price…"
                class="w-full max-w-[14rem] sm:max-w-xs md:max-w-sm rounded-full border border-[#E0D6C2] bg-white px-3.5 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        </form>

        <div class="flex items-center gap-2 sm:gap-3 text-[#1E1E1E] shrink-0 ml-auto">
            @if (auth()->user()?->canAccessAdmin())
                <a href="{{ route('admin.dashboard') }}" wire:navigate
                    class="text-sm font-medium text-[#C9A227] hover:underline">
                    Admin
                </a>
            @endif
            @auth
                <a href="{{ route('account.wishlist') }}" wire:navigate class="relative hover:text-[#C9A227] transition text-lg" title="Wishlist" aria-label="Wishlist">
                    ♡
                    @if ($wishlistCount > 0)
                        <span class="absolute -top-2 -right-2 min-w-[1.1rem] h-[1.1rem] rounded-full bg-[#C9A227] text-white text-[10px] font-semibold flex items-center justify-center px-1">
                            {{ $wishlistCount > 99 ? '99+' : $wishlistCount }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('account') }}" wire:navigate
                    class="text-sm text-[#6B6459] hover:text-[#C9A227] transition max-w-[8rem] truncate"
                    title="{{ auth()->user()->name }}">
                    {{ auth()->user()->name }}
                </a>
                <a href="{{ route('account.orders') }}" wire:navigate
                    class="text-sm text-[#6B6459] hover:text-[#C9A227] transition hidden md:inline"
                    title="Orders">
                    Orders
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-[#6B6459] hover:text-[#C9A227] transition">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" wire:navigate class="text-sm text-[#6B6459] hover:text-[#C9A227] transition">Login</a>
                <a href="{{ route('register') }}" wire:navigate
                    class="rounded-full border border-[#C9A227] px-3 py-1 text-xs font-medium text-[#C9A227] hover:bg-[#FAF6EF] transition">
                    Sign Up
                </a>
            @endauth

            <a href="{{ route('cart') }}" wire:navigate class="relative hover:text-[#C9A227] transition" title="Cart" aria-label="Cart">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l1.2 9.2a2 2 0 0 0 2 1.8h8.4a2 2 0 0 0 2-1.7L20 8H7"/>
                    <circle cx="10" cy="20" r="1.25" fill="currentColor"/>
                    <circle cx="17" cy="20" r="1.25" fill="currentColor"/>
                </svg>
                @if ($cartCount > 0)
                    <span class="absolute -top-2 -right-2 min-w-[1.1rem] h-[1.1rem] rounded-full bg-[#C9A227] text-white text-[10px] font-semibold flex items-center justify-center px-1">
                        {{ $cartCount > 99 ? '99+' : $cartCount }}
                    </span>
                @endif
            </a>
        </div>
    </div>

    {{-- Small screen bar --}}
    <div class="mx-auto max-w-6xl px-4 py-3 space-y-2 sm:hidden">
        <div class="flex items-center gap-3">
            <a href="{{ route('home') }}" wire:navigate class="flex items-center shrink-0 min-w-[9.5rem]" aria-label="Sundoritoma home">
                <img src="/img/settings/logo.png"
                     alt="Sundoritoma"
                     class="h-14 w-auto max-w-[12rem] object-contain object-left">
            </a>

            <div class="flex items-center gap-2 text-[#1E1E1E] shrink-0 ml-auto">
                @auth
                    <label for="mobile-nav-toggle"
                        class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#1E1E1E]"
                        aria-label="Open menu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <rect x="4" y="6" width="16" height="2" rx="1"/>
                            <rect x="4" y="11" width="16" height="2" rx="1"/>
                            <rect x="4" y="16" width="16" height="2" rx="1"/>
                        </svg>
                    </label>
                @else
                    <a href="{{ route('login') }}" wire:navigate class="text-sm text-[#6B6459] hover:text-[#C9A227] transition">Login</a>
                @endauth

                <a href="{{ route('cart') }}" wire:navigate class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center text-[#1E1E1E] hover:text-[#C9A227]" title="Cart" aria-label="Cart">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l1.2 9.2a2 2 0 0 0 2 1.8h8.4a2 2 0 0 0 2-1.7L20 8H7"/>
                        <circle cx="10" cy="20" r="1.25" fill="currentColor"/>
                        <circle cx="17" cy="20" r="1.25" fill="currentColor"/>
                    </svg>
                    @if ($cartCount > 0)
                        <span class="absolute top-0.5 right-0.5 min-w-[1.1rem] h-[1.1rem] rounded-full bg-[#C9A227] text-white text-[10px] font-semibold flex items-center justify-center px-1">
                            {{ $cartCount > 99 ? '99+' : $cartCount }}
                        </span>
                    @endif
                </a>
            </div>
        </div>

        <form action="{{ route('search') }}" method="get" class="w-full">
            <input type="search" name="q" value="{{ $query }}"
                placeholder="Search name, SKU or price…"
                class="w-full rounded-full border border-[#E0D6C2] bg-white px-3.5 py-1 text-sm leading-tight focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        </form>
    </div>
</header>

@auth
    {{-- Outside <header> so position:fixed is viewport-relative --}}
    <input type="checkbox" id="mobile-nav-toggle" class="mobile-nav-toggle" autocomplete="off">

    <div class="mobile-nav-drawer sm:hidden" role="dialog" aria-modal="true" aria-label="Account menu">
        <label for="mobile-nav-toggle" class="mobile-nav-drawer__backdrop" aria-label="Close menu"></label>

        <aside class="mobile-nav-drawer__panel">
            <div class="flex items-center justify-between gap-3 border-b border-[#E7DFCF] px-4 py-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Account</p>
                    <p class="truncate font-medium text-[#1E1E1E]">{{ auth()->user()->name }}</p>
                </div>
                <label for="mobile-nav-toggle"
                    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[#E0D6C2] bg-[#FAF6EF] text-[#1E1E1E]"
                    aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/>
                    </svg>
                </label>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-3 text-sm">
                @if (auth()->user()?->canAccessAdmin())
                    <a href="{{ route('admin.dashboard') }}" wire:navigate
                        onclick="document.getElementById('mobile-nav-toggle').checked = false"
                        class="mb-2 block rounded-xl bg-[#C9A227] px-4 py-3.5 font-semibold text-white">
                        Admin panel
                    </a>
                @endif

                <a href="{{ route('account') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="block rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('account') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    Overview
                </a>
                <a href="{{ route('account.profile') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="block rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('account.profile') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    Profile
                </a>
                <a href="{{ route('account.orders') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="block rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('account.orders*') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    Orders
                </a>
                <a href="{{ route('account.wishlist') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="flex items-center justify-between rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('account.wishlist') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    <span>Wishlist</span>
                    @if ($wishlistCount > 0)
                        <span class="rounded-full bg-[#C9A227] px-2 py-0.5 text-[10px] font-semibold text-white">{{ $wishlistCount > 99 ? '99+' : $wishlistCount }}</span>
                    @endif
                </a>
                <a href="{{ route('account.password') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="block rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('account.password') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    Change password
                </a>
                <a href="{{ route('cart') }}" wire:navigate
                    onclick="document.getElementById('mobile-nav-toggle').checked = false"
                    class="flex items-center justify-between rounded-xl px-4 py-3.5 text-[#1E1E1E] hover:bg-[#FAF6EF] {{ request()->routeIs('cart') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : '' }}">
                    <span>Cart</span>
                    @if ($cartCount > 0)
                        <span class="rounded-full bg-[#C9A227] px-2 py-0.5 text-[10px] font-semibold text-white">{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                    @endif
                </a>
            </nav>

            <div class="border-t border-[#E7DFCF] p-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="w-full rounded-xl border border-rose-200 bg-white px-4 py-3.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                        Logout
                    </button>
                </form>
            </div>
        </aside>
    </div>
@endauth
