<?php

namespace Tests\Feature;

use App\Livewire\StorefrontCart;
use App\Livewire\StorefrontCheckout;
use App\Livewire\StorefrontOrderDetail;
use App\Models\Area;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontOrderFlowBanglaPricesTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProduct(int $price = 1500): Product
    {
        return Product::query()->create([
            'name' => 'Bangla Price Necklace',
            'slug' => 'bangla-price-necklace',
            'sku' => 'BPN-1',
            'price' => $price,
            'purchase_price' => 400,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 1,
        ]);
    }

    #[Test]
    public function cart_shows_line_and_subtotal_in_bangla_digits(): void
    {
        $product = $this->publishedProduct(1500);
        app(CartService::class)->add($product->id, 2);

        Livewire::test(StorefrontCart::class)
            ->assertSeeHtml('&#2547; ১,৫০০')
            ->assertSeeHtml('&#2547; ৩,০০০')
            ->assertDontSeeHtml('&#2547; 1,500')
            ->assertDontSeeHtml('&#2547; 3,000');
    }

    #[Test]
    public function checkout_summary_shows_money_in_bangla_digits(): void
    {
        $product = $this->publishedProduct(1500);
        app(CartService::class)->add($product->id, 1);

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Dhanmondi',
            'slug' => 'dhaka-dhanmondi',
            'is_active' => true,
            'delivery_charge_upto_5' => 70,
            'delivery_charge_over_5' => 120,
        ]);

        Livewire::test(StorefrontCheckout::class)
            ->assertSeeHtml('&#2547; ১,৫০০')
            ->assertDontSeeHtml('&#2547; 1,500');
    }

    #[Test]
    public function order_detail_shows_totals_in_bangla_digits(): void
    {
        $user = User::factory()->create();
        $product = $this->publishedProduct(1500);

        $order = Order::query()->create([
            'order_number' => 'BN-'.uniqid(),
            'user_id' => $user->id,
            'name' => 'Buyer',
            'phone' => '01710000999',
            'address' => 'Dhaka',
            'city' => 'Dhaka',
            'status' => 'new',
            'subtotal' => 1500,
            'delivery_charge' => 70,
            'discount' => 0,
            'total' => 1570,
            'cod_amount' => 1570,
            'due_amount' => 1570,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1500,
            'purchase_price' => 400,
            'line_total' => 1500,
        ]);

        Livewire::actingAs($user)
            ->test(StorefrontOrderDetail::class, ['order' => $order->fresh(['items'])])
            ->assertSeeHtml('&#2547; ১,৫০০')
            ->assertSeeHtml('&#2547; ১,৫৭০')
            ->assertDontSeeHtml('&#2547; 1,500')
            ->assertDontSeeHtml('&#2547; 1,570');
    }
}
