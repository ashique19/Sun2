<x-storefront.shell>
    <div class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="font-serif text-3xl font-semibold mb-6">{{ str($page->name)->headline() }}</h1>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 md:p-8 prose prose-sm max-w-none text-[#6B6459] [&_h2]:font-serif [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-[#1E1E1E] [&_h2]:mt-6 [&_h2]:mb-2 [&_p]:mb-3">
            @if (filled($page->details))
                {!! $page->details !!}
            @else
                <p>Content for this page is being prepared.</p>
            @endif
        </div>
    </div>
</x-storefront.shell>
