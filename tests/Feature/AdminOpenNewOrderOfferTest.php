<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminOrderForm;
use App\Livewire\Admin\AdminOrders;
use App\Livewire\Admin\AdminOrderShow;
use App\Models\ChannelConversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOpenNewOrderOfferTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => (string) random_int(10000, 99999),
            'name' => 'Nila',
            'phone' => '01627237432',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'area' => 'Mirpur',
            'subtotal' => 1200,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 1280,
            'cod_amount' => 1280,
            'due_amount' => 1280,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => Order::STATUS_NEW,
            'placed_at' => now(),
        ], $overrides));
    }

    private function draftWithItem(Product $product, array $overrides = []): Order
    {
        $draft = $this->order(array_merge([
            'status' => Order::STATUS_DRAFT,
            'placed_via' => Order::PLACED_VIA_MESSENGER,
        ], $overrides));
        $draft->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1200,
            'purchase_price' => 500,
            'base_price' => 1200,
            'line_total' => 1200,
            'commission_rate' => 0,
            'commission_earned' => 0,
        ]);

        return $draft;
    }

    private function mockCustomerLookup(): void
    {
        $lookup = Mockery::mock(CustomerLookupService::class);
        $lookup->shouldReceive('lookup')->andReturn([
            'phone' => '01627237432',
            'valid' => true,
            'user' => null,
            'last_order' => null,
            'order_count' => 1,
            'orders' => collect(),
            'steadfast' => null,
            'steadfast_error' => null,
        ])->byDefault();
        $lookup->shouldReceive('formDefaultsFromOrder')->andReturn([
            'name' => '',
            'email' => '',
            'address' => '',
            'cityId' => null,
            'areaId' => null,
            'location_hint' => null,
        ])->byDefault();

        $this->app->instance(CustomerLookupService::class, $lookup);
    }

    public function test_draft_ai_list_offers_acknowledge_and_merge_when_a_new_order_exists(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $open = $this->order(['order_number' => '7001']);
        $open->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1200,
            'line_total' => 1200,
        ]);
        $this->draftWithItem($product, ['order_number' => '7002']);

        Livewire::actingAs($admin)
            ->test(AdminOrders::class, ['segment' => 'draft-ai'])
            ->assertSee('This phone already has a New order')
            ->assertSeeHtml('acknowledgeOpenNew')
            ->assertSeeHtml('mergeIntoOpenNew')
            ->assertSee('#7001');
    }

    public function test_draft_ai_list_hides_the_offer_when_there_is_no_open_new_order(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $this->order([
            'order_number' => '7101',
            'status' => 'dispatched',
        ]);
        $this->draftWithItem($product, ['order_number' => '7102']);

        Livewire::actingAs($admin)
            ->test(AdminOrders::class, ['segment' => 'draft-ai'])
            ->assertDontSee('This phone already has a New order')
            ->assertSee('Confirm → New');
    }

    public function test_draft_ai_acknowledge_opens_the_existing_new_order(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $open = $this->order(['order_number' => '7201']);
        $this->draftWithItem($product, ['order_number' => '7202']);

        Livewire::actingAs($admin)
            ->test(AdminOrders::class, ['segment' => 'draft-ai'])
            ->call('acknowledgeOpenNew', $open->id)
            ->assertRedirect(route('admin.orders.show', $open));
    }

    public function test_draft_ai_merge_folds_the_draft_into_the_new_order(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'PSID_OFFER',
            'last_inbound_at' => now(),
        ]);
        $open = $this->order(['order_number' => '7301']);
        $open->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'price' => 1200,
            'purchase_price' => 500,
            'line_total' => 1200,
        ]);
        $draft = $this->draftWithItem($product, [
            'order_number' => '7302',
            'channel_conversation_id' => $conversation->id,
        ]);
        $conversation->update(['draft_order_id' => $draft->id]);

        Livewire::actingAs($admin)
            ->test(AdminOrders::class, ['segment' => 'draft-ai'])
            ->call('mergeIntoOpenNew', $draft->id, $open->id)
            ->assertRedirect(route('admin.orders.show', $open));

        $this->assertNull($draft->fresh());
        $this->assertSame(2, (int) $open->fresh('items')->items->firstWhere('product_id', $product->id)?->quantity);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_draft_show_offers_acknowledge_and_merge(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $open = $this->order(['order_number' => '7401']);
        $draft = $this->draftWithItem($product, ['order_number' => '7402']);

        Livewire::actingAs($admin)
            ->test(AdminOrderShow::class, ['order' => $draft])
            ->assertSee('This phone already has a New order')
            ->assertSeeHtml('acknowledgeOpenNew')
            ->assertSeeHtml('mergeIntoOpenNew')
            ->assertSee('#7401')
            ->call('acknowledgeOpenNew', $open->id)
            ->assertRedirect(route('admin.orders.show', $open));
    }

    public function test_draft_show_merge_discards_the_draft(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $open = $this->order(['order_number' => '7501']);
        $draft = $this->draftWithItem($product, ['order_number' => '7502']);

        Livewire::actingAs($admin)
            ->test(AdminOrderShow::class, ['order' => $draft])
            ->call('mergeIntoOpenNew', $open->id)
            ->assertRedirect(route('admin.orders.show', $open));

        $this->assertNull($draft->fresh());
        $this->assertSame(1, $open->fresh('items')->items->count());
    }

    public function test_create_order_offers_acknowledge_and_merge_for_an_open_new(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        $product = $this->product();
        $open = $this->order(['order_number' => '7601']);

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01627237432')
            ->call('addProduct', $product->id)
            ->assertSee('This phone already has a New order')
            ->assertSeeHtml('acknowledgeOpenNew')
            ->assertSeeHtml('mergeIntoOpenNew')
            ->assertSee('#7601')
            ->call('acknowledgeOpenNew', $open->id)
            ->assertRedirect(route('admin.orders.show', $open));
    }

    public function test_create_order_merge_adds_form_lines_to_the_existing_new_order(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        $product = $this->product();
        $open = $this->order(['order_number' => '7701']);

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01627237432')
            ->call('addProduct', $product->id)
            ->call('mergeIntoOpenNew', $open->id)
            ->assertRedirect(route('admin.orders.show', $open));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, (int) $open->fresh('items')->items->firstWhere('product_id', $product->id)?->quantity);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_create_order_does_not_offer_merge_for_a_different_phone(): void
    {
        $this->actingAs($this->adminUser());
        $this->mockCustomerLookup();
        $this->order([
            'order_number' => '7801',
            'phone' => '01711111111',
        ]);

        Livewire::test(AdminOrderForm::class)
            ->set('phone', '01627237432')
            ->assertDontSee('This phone already has a New order');
    }
}
