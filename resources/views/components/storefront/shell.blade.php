@props(['query' => null])

@php
    $adsLab = app(\App\Services\Ads\AdsLabConfigService::class);
    $popunder = $adsLab->storefrontPopunder();
    $exitSmartlink = $adsLab->storefrontExitInterstitialUrl();
@endphp

<div class="storefront-shell">
    <x-storefront.announcement />
    <x-storefront.header :query="$query ?? ''" />

    <main id="main-content" class="pb-20 sm:pb-0">
        {{ $slot }}
    </main>

    <x-storefront.footer />

    <x-storefront.bottom-nav />

    @if ($popunder)
        <x-storefront.popunder :url="$popunder['url']" :script-src="$popunder['script_src']" />
    @endif

    @if ($exitSmartlink)
        <x-storefront.exit-interstitial :url="$exitSmartlink" />
    @endif
</div>
