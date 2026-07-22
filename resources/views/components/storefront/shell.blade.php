@props(['query' => null])

<div>
    <x-storefront.announcement />
    <x-storefront.header :query="$query ?? ''" />

    <main id="main-content" class="pb-16 sm:pb-0">
        {{ $slot }}
    </main>

    <x-storefront.bottom-nav />

    <x-storefront.footer />
</div>
