<nav class="rounded-xl border border-[#EFE7D6] bg-white p-4 space-y-1 text-sm">
    <a href="{{ route('account') }}" wire:navigate
        class="block rounded-lg px-3 py-2 {{ request()->routeIs('account') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
        Overview
    </a>
    <a href="{{ route('account.profile') }}" wire:navigate
        class="block rounded-lg px-3 py-2 {{ request()->routeIs('account.profile') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
        Profile
    </a>
    <a href="{{ route('account.password') }}" wire:navigate
        class="block rounded-lg px-3 py-2 {{ request()->routeIs('account.password') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
        Change Password
    </a>
    <a href="{{ route('account.orders') }}" wire:navigate
        class="block rounded-lg px-3 py-2 {{ request()->routeIs('account.orders*') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
        Order History
    </a>
    <a href="{{ route('account.wishlist') }}" wire:navigate
        class="block rounded-lg px-3 py-2 {{ request()->routeIs('account.wishlist') ? 'bg-[#FAF6EF] font-semibold text-[#C9A227]' : 'text-[#6B6459] hover:bg-[#FAF6EF]' }}">
        Wishlist
    </a>
</nav>
