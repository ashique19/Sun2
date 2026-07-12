@props([
    'variant' => 'sidebar',
    'onclick' => null,
])

@php
    $isModeratorOnly = auth()->user()?->isModeratorOnly() ?? false;
    $isMobile = $variant === 'mobile';
    $linkBase = $isMobile
        ? 'block rounded-xl px-4 text-[#1E1E1E] hover:bg-[#FAF6EF]'
        : 'block rounded-lg px-3 text-[#6B6459] hover:bg-[#FAF6EF]';
    $linkPad = $isMobile ? 'py-3.5' : 'py-2';
    $linkPadSm = $isMobile ? 'py-3' : 'py-1.5';
    $active = $isMobile
        ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]'
        : 'bg-[#FAF6EF] font-semibold text-[#C9A227]';
    $inactive = $isMobile ? '' : 'text-[#6B6459] hover:bg-[#FAF6EF]';
    $sectionLabel = $isMobile
        ? 'px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-[#8C8474]'
        : 'px-3 pt-1 pb-0.5 text-xs font-semibold uppercase tracking-wide text-[#8C8474]';
    $orderLinks = $isModeratorOnly
        ? ['admin.orders.new' => 'New']
        : [
            'admin.orders.create' => 'Create Order',
            'admin.orders.new' => 'New',
            'admin.orders.dispatched' => 'Dispatched',
            'admin.orders.delivered' => 'Delivered',
            'admin.orders.cancel-return' => 'Cancel & Return',
            'admin.orders.return-pending' => 'Return Pending',
        ];
    $click = $onclick ? 'onclick="'.$onclick.'"' : '';
@endphp

@unless ($isModeratorOnly)
    <a href="{{ route('admin.dashboard') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.dashboard') ? $active : $inactive }}">
        Dashboard
    </a>
@endunless

@if ($isMobile)
    <p class="{{ $sectionLabel }}">Orders</p>
@else
    <div class="space-y-1">
        <p class="{{ $sectionLabel }}">Orders</p>
@endif

@if ($isMobile)
    @foreach ($orderLinks as $routeName => $label)
        <a href="{{ route($routeName) }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPadSm }} {{ request()->routeIs($routeName) ? $active : $inactive }}">
            {{ $label }}
        </a>
    @endforeach
@else
    <div class="ml-3 space-y-0.5 border-l border-[#E7DFCF] pl-2">
        @foreach ($orderLinks as $routeName => $label)
            <a href="{{ route($routeName) }}"
                class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs($routeName) ? $active : $inactive }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
    </div>
@endif

@unless ($isModeratorOnly)
    <a href="{{ route('admin.products') }}" wire:navigate {!! $click !!}
        class="{{ $isMobile ? 'mt-2 ' : '' }}{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.products') || request()->routeIs('admin.products.create') || request()->routeIs('admin.products.edit') || request()->routeIs('admin.products*') ? $active : $inactive }}">
        Products
    </a>
    <a href="{{ route('admin.categories') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.categories*') ? $active : $inactive }}">
        Categories
    </a>
    <a href="{{ route('admin.coupons') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.coupons*') ? $active : $inactive }}">
        Coupons
    </a>
    <a href="{{ route('admin.hero-slides') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.hero-slides*') ? $active : $inactive }}">
        Hero Slides
    </a>
    <a href="{{ route('admin.couriers') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.couriers*') ? $active : $inactive }}">
        Couriers
    </a>
    <a href="{{ route('admin.cities') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.cities*') || request()->routeIs('admin.areas*') ? $active : $inactive }}">
        Cities &amp; Areas
    </a>
    <a href="{{ route('admin.reviews') }}" wire:navigate {!! $click !!}
        class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.reviews') ? $active : $inactive }}">
        Reviews
    </a>

    @if ($isMobile)
        <p class="{{ $sectionLabel }}">Users</p>
        <a href="{{ route('admin.users.customers') }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPadSm }} {{ request()->routeIs('admin.users.customers') ? $active : $inactive }}">
            Customers
        </a>
        <a href="{{ route('admin.users.moderators') }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPadSm }} {{ request()->routeIs('admin.users.moderators') ? $active : $inactive }}">
            Moderators
        </a>
        <p class="{{ $sectionLabel }}">Reports</p>
        <a href="{{ route('admin.reports.sales-by-month') }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.reports.sales-by-month') ? $active : $inactive }}">
            Sales by Month
        </a>
        <a href="{{ route('admin.sitemap') }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.sitemap') ? $active : $inactive }}">
            Sitemap
        </a>
        <a href="{{ route('admin.image-hashes') }}" wire:navigate {!! $click !!}
            class="{{ $linkBase }} {{ $linkPad }} {{ request()->routeIs('admin.image-hashes') ? $active : $inactive }}">
            Image Hashes
        </a>
    @else
        <div class="space-y-1 pt-2">
            <p class="{{ $sectionLabel }}">Users</p>
            <div class="ml-3 space-y-0.5 border-l border-[#E7DFCF] pl-2">
                <a href="{{ route('admin.users.customers') }}" wire:navigate
                    class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs('admin.users.customers') ? $active : $inactive }}">
                    Customers
                </a>
                <a href="{{ route('admin.users.moderators') }}" wire:navigate
                    class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs('admin.users.moderators') ? $active : $inactive }}">
                    Moderators
                </a>
            </div>
        </div>
        <div class="space-y-1 pt-2">
            <p class="{{ $sectionLabel }}">Reports</p>
            <div class="ml-3 space-y-0.5 border-l border-[#E7DFCF] pl-2">
                <a href="{{ route('admin.reports.sales-by-month') }}" wire:navigate
                    class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs('admin.reports.sales-by-month') ? $active : $inactive }}">
                    Sales by Month
                </a>
                <a href="{{ route('admin.sitemap') }}" wire:navigate
                    class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs('admin.sitemap') ? $active : $inactive }}">
                    Sitemap
                </a>
                <a href="{{ route('admin.image-hashes') }}" wire:navigate
                    class="block rounded-lg px-3 {{ $linkPadSm }} {{ request()->routeIs('admin.image-hashes') ? $active : $inactive }}">
                    Image Hashes
                </a>
            </div>
        </div>
    @endif
@endunless

@if ($isMobile)
    <a href="{{ route('home') }}" {!! $click !!}
        class="mt-2 {{ $linkBase }} {{ $linkPad }}">
        View Store
    </a>
@endif
