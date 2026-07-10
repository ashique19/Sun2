<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" type="image/png" href="/img/settings/favicon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#FAF6EF] text-[#1E1E1E]">
    {{ $slot }}

    <x-product-image-modal link-target="" link-label="View product" :show-external-icon="false" />
</body>
</html>
