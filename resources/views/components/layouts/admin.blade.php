<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin - Sundoritoma' }}</title>
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (request()->routeIs('admin.inbox'))
        @vite(['resources/js/inbox-product-crop.js', 'resources/js/inbox-image-edit.js'])
    @endif
    @livewireStyles
</head>
<body @class([
    'bg-[#FAF6EF] text-[#1E1E1E] antialiased',
    'min-h-screen' => ! request()->routeIs('admin.inbox'),
    'flex h-dvh flex-col overflow-hidden' => request()->routeIs('admin.inbox'),
])>
    @php
        $isModeratorOnly = auth()->user()?->isModeratorOnly() ?? false;
        $closeDrawer = "document.getElementById('admin-mobile-nav-toggle').checked = false";
    @endphp

    {{-- Small screen top bar (inside flex column on Inbox so it does not push content off-screen) --}}
    <div @class([
        'md:hidden border-b border-[#E7DFCF] bg-white px-4 py-3 flex items-center justify-between gap-3',
        'sticky top-0 z-20' => ! request()->routeIs('admin.inbox'),
        'shrink-0 z-20' => request()->routeIs('admin.inbox'),
    ])>
        <a href="{{ $isModeratorOnly ? route('admin.orders.new') : route('admin.dashboard') }}" wire:navigate class="font-serif font-semibold text-[#C9A227]">Admin</a>
        <div class="flex items-center gap-2">
            <x-admin.shortcut-icons />
            <a href="{{ route('home') }}" class="text-sm text-[#6B6459] hover:text-[#C9A227]">Store</a>
            <label for="admin-mobile-nav-toggle"
                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[#E0D6C2] bg-[#FAF6EF] text-[#1E1E1E]"
                aria-label="Open menu">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <rect x="4" y="6" width="16" height="2" rx="1"/>
                    <rect x="4" y="11" width="16" height="2" rx="1"/>
                    <rect x="4" y="16" width="16" height="2" rx="1"/>
                </svg>
            </label>
        </div>
    </div>

    <div @class([
        'flex',
        'min-h-screen' => ! request()->routeIs('admin.inbox'),
        'min-h-0 flex-1' => request()->routeIs('admin.inbox'),
    ])>
        <x-admin.sidebar />

        <div @class([
            'flex-1 min-w-0',
            'flex flex-col min-h-0' => request()->routeIs('admin.inbox'),
        ])>
            <header class="hidden md:flex border-b border-[#E7DFCF] bg-white/90 backdrop-blur px-4 sm:px-6 py-4 items-center justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-[#8C8474]">Sundoritoma Admin</p>
                    <p class="text-sm font-medium">{{ auth()->user()->name }}</p>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <a href="{{ route('home') }}" class="text-[#6B6459] hover:text-[#C9A227]">View Store</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-[#6B6459] hover:text-[#C9A227]">Logout</button>
                    </form>
                </div>
            </header>

            <main @class([
                'p-4 sm:p-6' => ! request()->routeIs('admin.inbox'),
                'flex min-h-0 flex-1 flex-col overflow-hidden p-0 xl:px-6 xl:py-4' => request()->routeIs('admin.inbox'),
            ])>
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Mobile drawer (outside sticky bar so fixed covers viewport) --}}
    <input type="checkbox" id="admin-mobile-nav-toggle" class="mobile-nav-toggle" autocomplete="off">

    <div class="mobile-nav-drawer md:hidden" role="dialog" aria-modal="true" aria-label="Admin menu">
        <label for="admin-mobile-nav-toggle" class="mobile-nav-drawer__backdrop" aria-label="Close menu"></label>

        <aside class="mobile-nav-drawer__panel">
            <div class="flex items-center justify-between gap-3 border-b border-[#E7DFCF] px-4 py-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Admin</p>
                    <p class="truncate font-medium text-[#1E1E1E]">{{ auth()->user()->name }}</p>
                </div>
                <label for="admin-mobile-nav-toggle"
                    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[#E0D6C2] bg-[#FAF6EF] text-[#1E1E1E]"
                    aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/>
                    </svg>
                </label>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-3 text-sm">
                <x-admin.nav-links variant="mobile" :onclick="$closeDrawer" />
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

    @livewireScripts

    <x-product-image-modal />
</body>
</html>
