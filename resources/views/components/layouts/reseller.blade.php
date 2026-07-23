<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Reseller' }} — Sundoritoma</title>
    <meta name="robots" content="noindex, nofollow">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#FAF6EF] text-[#1E1E1E] antialiased">
    <header class="sticky top-0 z-20 border-b border-[#E7DFCF] bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('reseller.dashboard') }}" wire:navigate class="font-serif text-lg font-semibold text-[#C9A227]">Reseller</a>
            <nav class="flex flex-wrap items-center gap-1 text-sm sm:gap-2">
                <a href="{{ route('reseller.dashboard') }}" wire:navigate
                    class="rounded-lg px-2.5 py-1.5 {{ request()->routeIs('reseller.dashboard') ? 'bg-[#FAF6EF] font-medium text-[#1E1E1E]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">Home</a>
                <a href="{{ route('reseller.orders.progress') }}" wire:navigate
                    class="rounded-lg px-2.5 py-1.5 {{ request()->routeIs('reseller.orders.progress') ? 'bg-[#FAF6EF] font-medium text-[#1E1E1E]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">In progress</a>
                <a href="{{ route('reseller.orders.history') }}" wire:navigate
                    class="rounded-lg px-2.5 py-1.5 {{ request()->routeIs('reseller.orders.history') ? 'bg-[#FAF6EF] font-medium text-[#1E1E1E]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">History</a>
                <a href="{{ route('reseller.wallet') }}" wire:navigate
                    class="rounded-lg px-2.5 py-1.5 {{ request()->routeIs('reseller.wallet') ? 'bg-[#FAF6EF] font-medium text-[#1E1E1E]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">Balance</a>
                <a href="{{ route('reseller.orders.create') }}" wire:navigate
                    class="rounded-lg bg-[#C9A227] px-2.5 py-1.5 font-medium text-white hover:bg-[#b8931f]">New order</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-5xl px-4 pb-8 text-center text-xs text-[#8C8474]">
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="underline hover:text-[#C9A227]">Logout</button>
        </form>
        <span class="mx-2">·</span>
        <a href="{{ route('home') }}" class="underline hover:text-[#C9A227]">Store</a>
    </footer>

    @livewireScripts
</body>
</html>
