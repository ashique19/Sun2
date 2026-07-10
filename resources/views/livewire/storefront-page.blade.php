<div>
    <x-storefront.announcement />
    <x-storefront.header />

    <div class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-6">{{ str($page->name)->headline() }}</h1>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 md:p-8 prose prose-sm max-w-none text-[#6B6459]">
            @if ($page->details)
                {!! $page->details !!}
            @elseif (view()->exists($defaultView = 'livewire.storefront-page-defaults.'.str($page->slug)->replace('-', '_')))
                @include($defaultView)
            @else
                <p>Content for this page is being prepared.</p>
            @endif
        </div>
    </div>

    <x-storefront.footer />
</div>
