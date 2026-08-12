@php
    $googleAnalyticsId = config('services.google.analytics_id');
@endphp
@if (filled($googleAnalyticsId))
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $googleAnalyticsId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $googleAnalyticsId }}');

        {{-- Livewire wire:navigate keeps the document head; re-send page_view on client navigations. --}}
        document.addEventListener('livewire:navigated', function () {
            if (window.__sunGtagInitialPageView === undefined) {
                window.__sunGtagInitialPageView = true;
                return;
            }
            gtag('event', 'page_view', {
                page_title: document.title,
                page_location: window.location.href,
                page_path: window.location.pathname + window.location.search,
            });
        });
    </script>
@endif
