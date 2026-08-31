@props([
    'url' => null,
    'scriptSrc' => null,
])

@php
    $url = filled($url) ? (string) $url : null;
    $scriptSrc = filled($scriptSrc) ? (string) $scriptSrc : null;
@endphp

@if ($scriptSrc)
    {{-- Official Adsterra (or network) popunder / social script --}}
    <script src="{{ $scriptSrc }}" data-sun-popunder-script async></script>
@elseif ($url)
    {{-- Smartlink popunder: first storefront click, once per session --}}
    <script data-sun-popunder-smartlink>
        (function () {
            var url = @json($url);
            var storageKey = 'sun_ads_popunder_fired';

            try {
                if (window.sessionStorage && sessionStorage.getItem(storageKey)) {
                    return;
                }
            } catch (e) {}

            function markFired() {
                try {
                    if (window.sessionStorage) {
                        sessionStorage.setItem(storageKey, '1');
                    }
                } catch (e) {}
            }

            function onClick(event) {
                if (event.defaultPrevented) {
                    return;
                }

                // Ignore modified clicks (open-in-new-tab / download intents).
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                    return;
                }

                markFired();
                document.removeEventListener('click', onClick, true);

                var popup = window.open(url, '_blank');
                if (popup) {
                    try {
                        popup.blur();
                        window.focus();
                    } catch (e) {}
                }
            }

            document.addEventListener('click', onClick, true);
        })();
    </script>
@endif
