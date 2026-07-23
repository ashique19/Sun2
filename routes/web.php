<?php

use App\Livewire\Admin\AdminAreaEdit;
use App\Livewire\Admin\AdminAreas;
use App\Livewire\Admin\AdminCategories;
use App\Livewire\Admin\AdminCategoryEdit;
use App\Livewire\Admin\AdminCities;
use App\Livewire\Admin\AdminCityEdit;
use App\Livewire\Admin\AdminCouponEdit;
use App\Livewire\Admin\AdminCoupons;
use App\Livewire\Admin\AdminCourierEdit;
use App\Livewire\Admin\AdminCouriers;
use App\Livewire\Admin\AdminCustomerShow;
use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\AdminHeroSlideEdit;
use App\Livewire\Admin\AdminHeroSlides;
use App\Livewire\Admin\AdminOrderForm;
use App\Livewire\Admin\AdminOrderShow;
use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProductPerformance;
use App\Livewire\Admin\AdminProducts;
use App\Livewire\Admin\AdminReviews;
use App\Livewire\Admin\AdminSalesByMonth;
use App\Livewire\Admin\AdminUserEdit;
use App\Livewire\Admin\AdminUsers;
use App\Livewire\Reseller\ResellerDashboard;
use App\Livewire\Reseller\ResellerOrderCreate;
use App\Livewire\Reseller\ResellerOrderShow;
use App\Livewire\Reseller\ResellerOrders;
use App\Livewire\Reseller\ResellerWallet;
use App\Livewire\StorefrontWishlist;
use App\Livewire\StorefrontAccount;
use App\Livewire\StorefrontCart;
use App\Livewire\StorefrontCategory;
use App\Livewire\StorefrontChangePassword;
use App\Livewire\StorefrontCheckout;
use App\Livewire\StorefrontForgotPassword;
use App\Livewire\StorefrontHome;
use App\Livewire\StorefrontLogin;
use App\Livewire\StorefrontOrderConfirmation;
use App\Livewire\StorefrontOrderDetail;
use App\Livewire\StorefrontOrderHistory;
use App\Livewire\StorefrontPage;
use App\Livewire\StorefrontProduct;
use App\Livewire\StorefrontProfile;
use App\Livewire\StorefrontRegister;
use App\Livewire\StorefrontResetPassword;
use App\Livewire\StorefrontSearch;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ProductImageHashRebuildController;
use App\Livewire\Admin\AdminSitemap;
use App\Livewire\Admin\AdminProductImageHashes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemaps/{file}', [SitemapController::class, 'child'])
    ->where('file', '[A-Za-z0-9._-]+\.xml')
    ->name('sitemap.child');
Route::get('/internal/sitemap/rebuild', [SitemapController::class, 'rebuild'])->name('sitemap.rebuild');
Route::get('/internal/product-image-hashes/rebuild', ProductImageHashRebuildController::class)
    ->name('product-image-hashes.rebuild');

Route::get('/', StorefrontHome::class)->name('home');
Route::get('/category/{category:slug}', StorefrontCategory::class)->name('category.show');
Route::get('/product/{product:slug}', StorefrontProduct::class)->name('product.show');
Route::get('/search', StorefrontSearch::class)->name('search');
Route::get('/cart', StorefrontCart::class)->name('cart');
Route::get('/checkout', StorefrontCheckout::class)->name('checkout');
Route::get('/checkout/confirmation/{order}', StorefrontOrderConfirmation::class)->name('checkout.confirmation');
Route::get('/page/{page:slug}', StorefrontPage::class)->name('page.show');
Route::get('/share/products/{token}', \App\Livewire\PublicProductShare::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('share.products');

Route::middleware('guest')->group(function () {
    Route::get('/register', StorefrontRegister::class)->name('register');
    Route::get('/login', StorefrontLogin::class)->name('login');
    Route::get('/forgot-password', StorefrontForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', StorefrontResetPassword::class)->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('home');
    })->name('logout');

    Route::get('/account', StorefrontAccount::class)->name('account');
    Route::get('/account/profile', StorefrontProfile::class)->name('account.profile');
    Route::get('/account/password', StorefrontChangePassword::class)->name('account.password');
    Route::get('/account/orders', StorefrontOrderHistory::class)->name('account.orders');
    Route::get('/account/orders/{order}', StorefrontOrderDetail::class)->name('account.orders.show');
    Route::get('/account/wishlist', StorefrontWishlist::class)->name('account.wishlist');
});

Route::middleware(['auth', 'role:reseller'])->prefix('reseller')->name('reseller.')->group(function () {
    Route::get('/', ResellerDashboard::class)->name('dashboard');
    Route::get('/orders/progress', ResellerOrders::class)->defaults('segment', 'progress')->name('orders.progress');
    Route::get('/orders/history', ResellerOrders::class)->defaults('segment', 'history')->name('orders.history');
    Route::get('/orders/create', ResellerOrderCreate::class)->name('orders.create');
    Route::get('/orders/{order}', ResellerOrderShow::class)->name('orders.show');
    Route::get('/wallet', ResellerWallet::class)->name('wallet');
});

Route::middleware(['auth', 'role:admin|dev|moderator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboard::class)->name('dashboard');

    Route::redirect('/orders', '/admin/orders/new');
    Route::get('/orders/new', AdminOrders::class)->defaults('segment', 'new')->name('orders.new');
    Route::get('/orders/print-selected', function (\Illuminate\Http\Request $request) {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $orders = \App\Models\Order::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (\App\Models\Order $order) => $ids->search($order->id))
            ->values();

        abort_if($orders->isEmpty(), 404);

        foreach ($orders as $order) {
            \App\Support\AdminAccess::ensureCanViewOrder($order);
        }

        return response()
            ->view('admin.orders-print-selected', [
                'orders' => $orders,
            ])
            ->header('Cache-Control', 'no-store');
    })->name('orders.print-selected');
    Route::get('/orders/{order}/print', function (\App\Models\Order $order) {
        \App\Support\AdminAccess::ensureCanViewOrder($order);

        $shippingAddress = collect([
            $order->address,
            $order->area,
            $order->city,
            $order->state,
        ])->filter(fn ($part) => filled($part))
            ->unique()
            ->implode(', ');

        return response()
            ->view('admin.order-print-label', [
                'order' => $order,
                'shippingAddress' => $shippingAddress,
                'parcelId' => $order->printParcelId(),
            ])
            ->header('Cache-Control', 'no-store');
    })->whereNumber('order')->name('orders.print');
    Route::get('/orders/{order}', AdminOrderShow::class)->whereNumber('order')->name('orders.show');

    Route::middleware('role:admin|dev')->group(function () {
        Route::get('/orders/dispatched', AdminOrders::class)->defaults('segment', 'dispatched')->name('orders.dispatched');
        Route::get('/orders/delivered', AdminOrders::class)->defaults('segment', 'delivered')->name('orders.delivered');
        Route::get('/orders/cancel-return', AdminOrders::class)->defaults('segment', 'cancel-return')->name('orders.cancel-return');
        Route::get('/orders/return-pending', AdminOrders::class)->defaults('segment', 'return-pending')->name('orders.return-pending');
        Route::get('/orders/all', AdminOrders::class)->defaults('segment', 'all')->name('orders.all');
        Route::get('/orders/create', AdminOrderForm::class)->name('orders.create');
        Route::get('/orders/{order}/edit', AdminOrderForm::class)->whereNumber('order')->name('orders.edit');
        Route::get('/products', AdminProducts::class)->name('products');
        Route::get('/products/create', AdminProductEdit::class)->name('products.create');
        Route::get('/products/{product:id}/performance', AdminProductPerformance::class)->whereNumber('product')->name('products.performance');
        Route::get('/products/{product:id}/edit', AdminProductEdit::class)->name('products.edit');
        Route::get('/categories', AdminCategories::class)->name('categories');
        Route::get('/categories/create', AdminCategoryEdit::class)->name('categories.create');
        Route::get('/categories/{category}/edit', AdminCategoryEdit::class)->name('categories.edit');
        Route::get('/coupons', AdminCoupons::class)->name('coupons');
        Route::get('/coupons/create', AdminCouponEdit::class)->name('coupons.create');
        Route::get('/coupons/{coupon}/edit', AdminCouponEdit::class)->name('coupons.edit');
        Route::get('/hero-slides', AdminHeroSlides::class)->name('hero-slides');
        Route::get('/hero-slides/create', AdminHeroSlideEdit::class)->name('hero-slides.create');
        Route::get('/hero-slides/{slide}/edit', AdminHeroSlideEdit::class)->name('hero-slides.edit');
        Route::get('/couriers', AdminCouriers::class)->name('couriers');
        Route::get('/couriers/create', AdminCourierEdit::class)->name('couriers.create');
        Route::get('/couriers/{courier}/edit', AdminCourierEdit::class)->name('couriers.edit');
        Route::get('/cities', AdminCities::class)->name('cities');
        Route::get('/cities/create', AdminCityEdit::class)->name('cities.create');
        Route::get('/cities/{city}/edit', AdminCityEdit::class)->name('cities.edit');
        Route::get('/areas', AdminAreas::class)->name('areas');
        Route::get('/areas/create', AdminAreaEdit::class)->name('areas.create');
        Route::get('/areas/{area}/edit', AdminAreaEdit::class)->name('areas.edit');
        Route::get('/reviews', AdminReviews::class)->name('reviews');
        Route::redirect('/users', '/admin/users/customers');
        Route::get('/users/customers', AdminUsers::class)->defaults('segment', 'customers')->name('users.customers');
        Route::get('/users/moderators', AdminUsers::class)->defaults('segment', 'moderators')->name('users.moderators');
        Route::get('/users/create', AdminUserEdit::class)->name('users.create');
        Route::get('/customers/{user}', AdminCustomerShow::class)->whereNumber('user')->name('customers.show');
        Route::get('/users/{user}/edit', AdminUserEdit::class)->whereNumber('user')->name('users.edit');
        Route::get('/reports/sales-by-month', AdminSalesByMonth::class)->name('reports.sales-by-month');
        Route::get('/sitemap', AdminSitemap::class)->name('sitemap');
        Route::get('/image-hashes', AdminProductImageHashes::class)->name('image-hashes');
    });
});
