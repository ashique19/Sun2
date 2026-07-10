<aside class="hidden md:flex w-56 lg:w-64 shrink-0 flex-col border-r border-[#E7DFCF] bg-white min-h-screen">
    <div class="px-5 py-6 border-b border-[#E7DFCF]">
        <a href="{{ route('admin.dashboard') }}" wire:navigate class="font-serif text-xl font-semibold text-[#C9A227]">
            Admin
        </a>
    </div>
    <nav class="p-4 space-y-1 text-sm">
        <a href="{{ route('admin.dashboard') }}" wire:navigate
            class="block rounded-lg px-3 py-2 {{ request()->routeIs('admin.dashboard') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
            Dashboard
        </a>

        <div class="space-y-1">
            <p class="px-3 pt-1 pb-0.5 text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Orders</p>
            @php
                $orderLinks = [
                    'admin.orders.create' => 'Create Order',
                    'admin.orders.new' => 'New',
                    'admin.orders.dispatched' => 'Dispatched',
                    'admin.orders.delivered' => 'Delivered',
                    'admin.orders.cancel-return' => 'Cancel & Return',
                    'admin.orders.return-pending' => 'Return Pending',
                ];
            @endphp
            <div class="ml-3 space-y-0.5 border-l border-[#E7DFCF] pl-2">
                @foreach ($orderLinks as $routeName => $label)
                    <a href="{{ route($routeName) }}"
                        class="block rounded-lg px-3 py-1.5 {{ request()->routeIs($routeName) ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <a href="{{ route('admin.products') }}" wire:navigate
            class="block rounded-lg px-3 py-2 {{ request()->routeIs('admin.products') || request()->routeIs('admin.products.create') || request()->routeIs('admin.products.edit') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
            Products
        </a>
        <a href="{{ route('admin.categories') }}" wire:navigate
            class="block rounded-lg px-3 py-2 {{ request()->routeIs('admin.categories*') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
            Categories
        </a>
        <a href="{{ route('admin.couriers') }}" wire:navigate
            class="block rounded-lg px-3 py-2 {{ request()->routeIs('admin.couriers*') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
            Couriers
        </a>
        <a href="{{ route('admin.reviews') }}" wire:navigate
            class="block rounded-lg px-3 py-2 {{ request()->routeIs('admin.reviews') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
            Reviews
        </a>

        <div class="space-y-1 pt-2">
            <p class="px-3 pt-1 pb-0.5 text-xs font-semibold uppercase tracking-wide text-[#8C8474]">Reports</p>
            <div class="ml-3 space-y-0.5 border-l border-[#E7DFCF] pl-2">
                <a href="{{ route('admin.reports.sales-by-month') }}" wire:navigate
                    class="block rounded-lg px-3 py-1.5 {{ request()->routeIs('admin.reports.sales-by-month') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                    Sales by Month
                </a>
            </div>
        </div>
    </nav>
</aside>
