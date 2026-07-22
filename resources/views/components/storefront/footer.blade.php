<footer class="border-t border-[#E7DFCF] bg-white mt-12">
    <div class="mx-auto max-w-6xl px-4 py-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-5 text-sm">
        <div>
            <h3 class="font-serif text-lg font-semibold mb-3">Sundoritoma</h3>
            <p class="text-[#6B6459] leading-relaxed">
                {{ __('storefront.handmade_tagline') }}
            </p>
        </div>
        <div>
            <h4 class="font-semibold mb-3">{{ __('storefront.shop') }}</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li><a href="{{ route('home') }}#collection" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.categories') }}</a></li>
                <li><a href="{{ route('search') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.search_products') }}</a></li>
                <li><a href="{{ route('cart') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.shopping_cart') }}</a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">{{ __('storefront.account_section') }}</h4>
            <ul class="space-y-2 text-[#6B6459]">
                @auth
                    <li><a href="{{ route('account') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.my_account') }}</a></li>
                    <li><a href="{{ route('account.orders') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.order_history') }}</a></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="hover:text-[#C9A227]">{{ __('storefront.logout') }}</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('login') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.login') }}</a></li>
                    <li><a href="{{ route('register') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.create_account') }}</a></li>
                @endauth
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">{{ __('storefront.customer_care') }}</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li>{{ __('storefront.helpline_label') }}: <a href="{{ config('seo.whatsapp_url') }}" target="_blank" rel="noopener noreferrer" class="hover:text-[#C9A227]">{{ config('seo.whatsapp_display') }}</a></li>
                <li>{{ __('storefront.email_label') }}: info@sundoritoma.com</li>
                <li>{{ __('storefront.delivery_all_bd') }}</li>
                <li>{{ __('storefront.cod_available') }}</li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">{{ __('storefront.information') }}</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li><a href="{{ route('page.show', 'about-us') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.about_us') }}</a></li>
                <li><a href="{{ route('page.show', 'privacy-policy') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.privacy') }}</a></li>
                <li><a href="{{ route('page.show', 'terms-of-service') }}" wire:navigate class="hover:text-[#C9A227]">{{ __('storefront.terms') }}</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-[#E7DFCF] py-4 text-center text-xs text-[#8C8474]">
        &copy; {{ date('Y') }} {{ __('storefront.copyright') }}
    </div>
</footer>
