@props(['query' => null])

<div class="storefront-shell">
    <x-storefront.announcement />
    <x-storefront.header :query="$query ?? ''" />

    <main id="main-content" class="pb-20 sm:pb-0">
        {{ $slot }}
    </main>

    <x-storefront.footer />

    <x-storefront.bottom-nav />
</div>
