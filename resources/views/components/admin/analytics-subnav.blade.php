@props(['active' => 'hub'])

@php
    $links = [
        'hub' => ['route' => 'admin.analytics', 'label' => 'Overview'],
        'pnl' => ['route' => 'admin.analytics.pnl', 'label' => 'Profit & loss'],
        'orders-costs' => ['route' => 'admin.analytics.orders-with-costs', 'label' => 'All orders with costs'],
        'ordered' => ['route' => 'admin.analytics.ordered-delivered', 'label' => 'Ordered vs delivered'],
        'category' => ['route' => 'admin.analytics.category-revenue', 'label' => 'By category'],
    ];
@endphp

<nav class="mb-5 flex flex-wrap gap-2" aria-label="Analytics sections">
    @foreach ($links as $key => $link)
        <a href="{{ route($link['route']) }}" wire:navigate
            @class([
                'rounded-full px-3 py-1.5 text-xs font-medium transition border',
                'border-[#C9A227] bg-[#FAF6EF] text-[#1E1E1E]' => $active === $key,
                'border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227]' => $active !== $key,
            ])>
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
