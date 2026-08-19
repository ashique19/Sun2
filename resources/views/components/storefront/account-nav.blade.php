@php
    $items = [
        [
            'route' => 'account',
            'active' => request()->routeIs('account'),
            'label' => __('storefront.overview'),
        ],
        [
            'route' => 'account.profile',
            'active' => request()->routeIs('account.profile'),
            'label' => __('storefront.profile'),
        ],
        [
            'route' => 'account.password',
            'active' => request()->routeIs('account.password'),
            'label' => auth()->user()->password ? __('storefront.change_password') : __('storefront.set_password_nav'),
        ],
        [
            'route' => 'account.orders',
            'active' => request()->routeIs('account.orders*'),
            'label' => __('storefront.order_history'),
        ],
        [
            'route' => 'account.wishlist',
            'active' => request()->routeIs('account.wishlist'),
            'label' => __('storefront.wishlist'),
        ],
    ];
@endphp

<nav class="storefront-account-nav" aria-label="{{ __('storefront.my_account') }}">
    <div class="storefront-account-nav__pills lg:hidden">
        @foreach ($items as $item)
            <a href="{{ route($item['route']) }}" wire:navigate
                @class([
                    'storefront-account-nav__pill',
                    'is-active' => $item['active'],
                ])>
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>

    <div class="storefront-account-nav__sidebar hidden lg:block">
        @foreach ($items as $item)
            <a href="{{ route($item['route']) }}" wire:navigate
                @class([
                    'storefront-account-nav__sidebar-link',
                    'is-active' => $item['active'],
                ])>
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
