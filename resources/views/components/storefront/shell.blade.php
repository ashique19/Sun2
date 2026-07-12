@props(['query' => null])

<div>
    <x-storefront.announcement />
    <x-storefront.header :query="$query ?? ''" />

    <main id="main-content">
        {{ $slot }}
    </main>

    <x-storefront.footer />
</div>
