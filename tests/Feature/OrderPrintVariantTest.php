<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\OrderPrintVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderPrintVariantTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => '26765',
            'name' => 'Alyssa Russo',
            'phone' => '01832116687',
            'address' => "Ivy's Legacy, Apt # 3A",
            'city' => 'Dhaka',
            'area' => 'Gulshan',
            'status' => 'new',
            'subtotal' => 3120,
            'delivery_charge' => 80,
            'charge' => 0,
            'discount' => 0,
            'total' => 3200,
            'cod_amount' => 0,
            'due_amount' => 0,
            'courier_consignment_id' => '270697676',
            'placed_at' => now(),
        ], $overrides));
    }

    private function orderWithPrimaryPhoto(): Order
    {
        $product = Product::query()->create([
            'name' => 'Gold Necklace',
            'slug' => 'gold-necklace-print-'.uniqid(),
            'sku' => 'GN-PRINT',
            'price' => 3120,
            'purchase_price' => 1200,
            'stock_quantity' => 4,
            'is_published' => true,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'img/products/gold-necklace.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $order = $this->order();

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Stale snapshot name',
            'product_image' => 'img/order/old-snap.jpg',
            'quantity' => 2,
            'price' => 1560,
            'purchase_price' => 600,
            'unit_cost' => 600,
            'line_total' => 3120,
        ]);

        return $order->fresh(['items.product.images', 'adjustments']);
    }

    public function test_courier_detailed_shows_address_helpline_and_collectable_due(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.orders.print', [
            'order' => $this->order(),
            'variant' => OrderPrintVariant::CourierDetailed->value,
        ]))
            ->assertOk()
            ->assertSee('Parcel ID', false)
            ->assertSee('270697676', false)
            ->assertSee('SUNDORITOMA.COM', false)
            ->assertSee('WhatsApp: 01880001255', false)
            ->assertSee('Alyssa Russo', false)
            ->assertSee('01832116687', false)
            ->assertSee("Ivy's Legacy, Apt # 3A, Gulshan, Dhaka")
            ->assertSee('TOTAL DUE', false)
            ->assertSee('3,200 Tk', false)
            ->assertDontSee('print-otg-app', false)
            ->assertDontSee('sundoritoma://print', false)
            ->assertDontSee('setTimeout(function () { window.print();', false)
            ->assertDontSee('Gold Necklace', false);
    }

    public function test_explicit_courier_minimal_keeps_otg_and_omits_due(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.orders.print', [
            'order' => $this->order(),
            'variant' => OrderPrintVariant::CourierMinimal->value,
        ]))
            ->assertOk()
            ->assertSee('PARCEL ID', false)
            ->assertSee('270697676', false)
            ->assertSee('SUNDORITOMA.COM', false)
            ->assertSee('Alyssa Russo', false)
            ->assertSee('print-otg-app', false)
            ->assertSee('sundoritoma://print?slips_url=', false)
            ->assertDontSee('TOTAL DUE', false)
            ->assertDontSee('WhatsApp', false)
            ->assertDontSee('01832116687', false)
            ->assertDontSee("Ivy's Legacy", false);
    }

    public function test_customer_detailed_invoice_uses_product_primary_photo(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithPrimaryPhoto();

        $this->get(route('admin.orders.print', [
            'order' => $order,
            'variant' => OrderPrintVariant::CustomerDetailed->value,
        ]))
            ->assertOk()
            ->assertSee('Invoice', false)
            ->assertSee('Order #26765', false)
            ->assertSee('Gold Necklace', false)
            ->assertDontSee('Stale snapshot name', false)
            ->assertSee('data-product-photo', false)
            ->assertSee('gold-necklace.jpg', false)
            ->assertDontSee('old-snap.jpg', false)
            ->assertSee('Bill to customer', false)
            ->assertSee('Amount to collect', false)
            ->assertSee('3,200 Tk', false)
            ->assertSee('Qty', false)
            ->assertSee('WhatsApp: 01880001255', false)
            ->assertDontSee('print-otg-app', false)
            ->assertDontSee('TOTAL DUE', false);
    }

    public function test_customer_minimal_invoice_omits_product_photos(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->orderWithPrimaryPhoto();

        $this->get(route('admin.orders.print', [
            'order' => $order,
            'variant' => OrderPrintVariant::CustomerMinimal->value,
        ]))
            ->assertOk()
            ->assertSee('Invoice', false)
            ->assertSee('Gold Necklace', false)
            ->assertSee('3,200 Tk', false)
            ->assertDontSee('data-product-photo', false)
            ->assertDontSee('gold-necklace.jpg', false)
            ->assertDontSee('old-snap.jpg', false)
            ->assertDontSee('<img', false);
    }

    public function test_unknown_print_variant_is_not_found(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.orders.print', [
            'order' => $this->order(),
            'variant' => 'thermal-fancy',
        ]))->assertNotFound();
    }

    public function test_orders_list_print_is_a_dropdown_of_four_variants(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->order();

        $component = Livewire::test(AdminOrders::class, ['segment' => 'new']);

        $component
            ->assertSee('Dispatch')
            ->assertSeeHtml('data-order-actions')
            ->assertSeeHtml('flex-nowrap')
            ->assertSeeHtml('data-order-print-menu')
            ->assertSeeHtml('data-order-action="print"')
            ->assertSeeHtml('data-order-action="repeat"')
            ->assertSeeHtml('data-order-action="delete"')
            ->assertSeeHtml('aria-label="Print"')
            ->assertSeeHtml('aria-label="Repeat order"')
            ->assertSeeHtml('aria-label="Delete order"')
            ->assertSeeHtml('position:fixed')
            ->assertDontSeeHtml('>Print</')
            ->assertDontSeeHtml('>Repeat</')
            ->assertDontSeeHtml('>Delete</');

        foreach (OrderPrintVariant::cases() as $variant) {
            $component
                ->assertSee($variant->label())
                ->assertSeeHtml('data-order-print-variant="'.$variant->value.'"')
                ->assertSeeHtml(route('admin.orders.print', [
                    'order' => $order,
                    'variant' => $variant->value,
                ]));
        }
    }

    public function test_order_show_print_icon_is_a_dropdown_of_four_variants(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->order();

        $component = Livewire::test(AdminOrderShow::class, ['order' => $order]);

        $component
            ->assertSeeHtml('data-order-print-menu')
            ->assertSeeHtml('aria-label="Print"');

        foreach (OrderPrintVariant::cases() as $variant) {
            $component
                ->assertSee($variant->label())
                ->assertSeeHtml('data-order-print-variant="'.$variant->value.'"');
        }
    }
}
