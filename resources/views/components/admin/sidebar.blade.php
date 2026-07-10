@php
    $isModeratorOnly = auth()->user()?->isModeratorOnly() ?? false;
@endphp
<aside class="hidden md:flex w-56 lg:w-64 shrink-0 flex-col border-r border-[#E7DFCF] bg-white min-h-screen">
    <div class="px-5 py-6 border-b border-[#E7DFCF]">
        <a href="{{ $isModeratorOnly ? route('admin.orders.new') : route('admin.dashboard') }}" wire:navigate class="font-serif text-xl font-semibold text-[#C9A227]">
            Admin
        </a>
    </div>
    <nav class="p-4 space-y-1 text-sm">
        <x-admin.nav-links variant="sidebar" />
    </nav>
</aside>
