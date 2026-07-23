<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $seoTitle = $title ?? config('seo.default_title');
        $seoOgTitle = $seoOgTitle ?? $seoTitle;
        $seoDescription = $seoDescription ?? \App\Support\Seo::description(null);
        $seoCanonical = $seoCanonical ?? url()->current();
        $seoImage = \App\Support\Seo::absoluteUrl($seoImage ?? null);
        $seoImageAlt = $seoImageAlt ?? $seoOgTitle;
        $seoRobots = \App\Support\Seo::robots($seoRobots ?? null);
        $seoType = $seoType ?? 'website';
        $seoSiteName = config('seo.site_name');
        $seoPriceAmount = $seoPriceAmount ?? null;
        $seoPriceCurrency = $seoPriceCurrency ?? 'BDT';
        $seoLocale = $seoLocale ?? config('seo.og_locale', 'bn_BD');
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="robots" content="{{ $seoRobots }}">
    <link rel="canonical" href="{{ $seoCanonical }}">

    <meta property="og:site_name" content="{{ $seoSiteName }}">
    <meta property="og:locale" content="{{ $seoLocale }}">
    <meta property="og:type" content="{{ $seoType }}">
    <meta property="og:title" content="{{ $seoOgTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:image" content="{{ $seoImage }}">
    <meta property="og:image:alt" content="{{ $seoImageAlt }}">
    @if ($seoPriceAmount !== null)
        <meta property="product:price:amount" content="{{ $seoPriceAmount }}">
        <meta property="product:price:currency" content="{{ $seoPriceCurrency }}">
    @endif

    <meta name="twitter:card" content="{{ config('seo.twitter_card') }}">
    <meta name="twitter:title" content="{{ $seoOgTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoImage }}">
    <meta name="twitter:image:alt" content="{{ $seoImageAlt }}">

    <link rel="icon" type="image/png" href="/img/settings/favicon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-seo.json-ld :data="\App\Support\JsonLd::organization()" />
    @isset($seoJsonLd)
        @foreach ((array) $seoJsonLd as $jsonLdBlock)
            <x-seo.json-ld :data="$jsonLdBlock" />
        @endforeach
    @endisset
</head>
<body class="min-h-screen bg-[#FAF6EF] text-[#1E1E1E]">
    {{ $slot }}

    <x-product-image-modal link-target="" link-label="View product" :show-external-icon="false" />
</body>
</html>
