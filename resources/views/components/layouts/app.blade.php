<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $seoTitle = $title ?? config('seo.default_title');
        $seoDescription = $seoDescription ?? \App\Support\Seo::description(null);
        $seoCanonical = $seoCanonical ?? url()->current();
        $seoImage = \App\Support\Seo::absoluteUrl($seoImage ?? null);
        $seoRobots = \App\Support\Seo::robots($seoRobots ?? null);
        $seoType = $seoType ?? 'website';
        $seoSiteName = config('seo.site_name');
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="robots" content="{{ $seoRobots }}">
    <link rel="canonical" href="{{ $seoCanonical }}">

    <meta property="og:site_name" content="{{ $seoSiteName }}">
    <meta property="og:type" content="{{ $seoType }}">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $seoCanonical }}">
    <meta property="og:image" content="{{ $seoImage }}">

    <meta name="twitter:card" content="{{ config('seo.twitter_card') }}">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoImage }}">

    <link rel="icon" type="image/png" href="/img/settings/favicon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-seo.json-ld :data="\App\Support\JsonLd::organization()" />
</head>
<body class="min-h-screen bg-[#FAF6EF] text-[#1E1E1E]">
    {{ $slot }}

    <x-product-image-modal link-target="" link-label="View product" :show-external-icon="false" />
</body>
</html>
