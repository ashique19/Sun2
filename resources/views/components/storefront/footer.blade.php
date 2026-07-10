<footer class="border-t border-[#E7DFCF] bg-white mt-12">
    <div class="mx-auto max-w-6xl px-4 py-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-5 text-sm">
        <div>
            <h3 class="font-serif text-lg font-semibold mb-3">Sundoritoma</h3>
            <p class="text-[#6B6459] leading-relaxed">
                Traditional &amp; imitation jewelry — German silver, brass, beads, and exclusive handcrafted collections.
            </p>
        </div>
        <div>
            <h4 class="font-semibold mb-3">Shop</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li><a href="{{ route('home') }}#collection" wire:navigate class="hover:text-[#C9A227]">Categories</a></li>
                <li><a href="{{ route('search') }}" wire:navigate class="hover:text-[#C9A227]">Search Products</a></li>
                <li><a href="{{ route('cart') }}" wire:navigate class="hover:text-[#C9A227]">Shopping Cart</a></li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">Account</h4>
            <ul class="space-y-2 text-[#6B6459]">
                @auth
                    <li><a href="{{ route('account') }}" wire:navigate class="hover:text-[#C9A227]">My Account</a></li>
                    <li><a href="{{ route('account.orders') }}" wire:navigate class="hover:text-[#C9A227]">Order History</a></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="hover:text-[#C9A227]">Logout</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('login') }}" wire:navigate class="hover:text-[#C9A227]">Login</a></li>
                    <li><a href="{{ route('register') }}" wire:navigate class="hover:text-[#C9A227]">Create Account</a></li>
                @endauth
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">Customer Care</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li>Helpline: 01880001255</li>
                <li>info@sundoritoma.com</li>
                <li>Free delivery inside Dhaka</li>
                <li>Cash on Delivery available</li>
            </ul>
        </div>
        <div>
            <h4 class="font-semibold mb-3">Information</h4>
            <ul class="space-y-2 text-[#6B6459]">
                <li><a href="{{ route('page.show', 'about-us') }}" wire:navigate class="hover:text-[#C9A227]">About Us</a></li>
                <li><a href="{{ route('page.show', 'privacy-policy') }}" wire:navigate class="hover:text-[#C9A227]">Privacy Policy</a></li>
                <li><a href="{{ route('page.show', 'terms-of-service') }}" wire:navigate class="hover:text-[#C9A227]">Terms of Service</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-[#E7DFCF] py-4 text-center text-xs text-[#8C8474]">
        &copy; {{ date('Y') }} Sundoritoma &middot; All rights reserved
    </div>
</footer>
