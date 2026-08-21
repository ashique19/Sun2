@props([
    'product',
])

@php
    $previous = \App\Support\StorefrontProductNavigator::previous($product);
    $next = \App\Support\StorefrontProductNavigator::next($product);
@endphp

@if ($previous || $next)
    <nav {{ $attributes->class('mt-4 flex items-stretch justify-between gap-3') }} aria-label="{{ __('storefront.product_navigation') }}">
        <div class="min-w-0 flex-1">
            @if ($previous)
                <a href="{{ route('product.show', $previous) }}" wire:navigate
                    class="group inline-flex max-w-full items-center gap-2 rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm text-[#6B6459] transition hover:border-[#C9A227] hover:text-[#7A6114]">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-[#7A6114]" aria-hidden="true">
                        <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
                    </svg>
                    <span class="min-w-0">
                        <span class="block text-[11px] uppercase tracking-wide text-[#5C564C]">{{ __('storefront.previous_product') }}</span>
                        <span class="block truncate font-medium text-[#1E1E1E] group-hover:text-[#7A6114]">{{ $previous->name }}</span>
                    </span>
                </a>
            @endif
        </div>
        <div class="min-w-0 flex-1 flex justify-end">
            @if ($next)
                <a href="{{ route('product.show', $next) }}" wire:navigate
                    class="group inline-flex max-w-full items-center gap-2 rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm text-[#6B6459] transition hover:border-[#C9A227] hover:text-[#7A6114] text-right">
                    <span class="min-w-0">
                        <span class="block text-[11px] uppercase tracking-wide text-[#5C564C]">{{ __('storefront.next_product') }}</span>
                        <span class="block truncate font-medium text-[#1E1E1E] group-hover:text-[#7A6114]">{{ $next->name }}</span>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-[#7A6114]" aria-hidden="true">
                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>
                </a>
            @endif
        </div>
    </nav>
@endif
