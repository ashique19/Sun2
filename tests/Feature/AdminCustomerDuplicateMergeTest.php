<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCustomerShow;
use App\Models\Address;
use App\Models\Area;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Admin\CustomerDuplicateMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomerDuplicateMergeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function customer(string $name, string $phone, array $extra = []): User
    {
        Role::findOrCreate('customers');

        $user = User::factory()->create(array_merge([
            'name' => $name,
            'phone' => $phone,
            'password' => 'SecretPass1!',
        ], $extra));
        $user->assignRole('customers');

        return $user;
    }

    private function orderFor(User $customer, string $orderNumber): Order
    {
        return Order::query()->create([
            'order_number' => $orderNumber,
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);
    }

    private function product(string $slug): Product
    {
        return Product::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'price' => 100,
            'is_published' => true,
        ]);
    }

    #[Test]
    public function customer_profile_loads_when_address_city_relation_exists(): void
    {
        $this->actingAs($this->adminUser());

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-profile-safe',
            'is_active' => true,
        ]);
        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Gulshan',
            'slug' => 'gulshan-profile-safe',
            'is_active' => true,
        ]);

        $customer = $this->customer('Safe Profile', '01710001111');
        Address::query()->create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'Road 12',
            'city_id' => $city->id,
            'area_id' => $area->id,
            'is_default' => true,
        ]);

        Livewire::test(AdminCustomerShow::class, ['user' => $customer])
            ->assertOk()
            ->assertSet('displayCity', 'Dhaka')
            ->assertSet('displayArea', 'Gulshan')
            ->assertSee('Safe Profile');
    }

    #[Test]
    public function customer_profile_does_not_crash_when_address_city_string_column_is_empty(): void
    {
        $this->actingAs($this->adminUser());

        $city = City::query()->create([
            'name' => 'Chattogram',
            'slug' => 'ctg-profile-safe',
            'is_active' => true,
        ]);

        $customer = $this->customer('Shadow Column', '01710002222');
        Address::query()->create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'Road 3',
            'city' => '',
            'area' => '',
            'city_id' => $city->id,
            'is_default' => true,
        ]);

        Livewire::test(AdminCustomerShow::class, ['user' => $customer])
            ->assertOk()
            ->assertSet('displayCity', 'Chattogram');
    }

    #[Test]
    public function merge_keeps_latest_customer_and_moves_orders(): void
    {
        $this->actingAs($this->adminUser());

        $older = $this->customer('Older Twin', '01712223344');
        $newer = $this->customer('Newer Twin', '01712223345');
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801712223344']);
        $newer->refresh();

        $oldOrder = $this->orderFor($older, '1001');
        $newOrder = $this->orderFor($newer, '1002');

        $productOne = $this->product('wish-one');
        $productTwo = $this->product('wish-two');
        Wishlist::query()->create(['user_id' => $older->id, 'product_id' => $productOne->id]);
        Wishlist::query()->create(['user_id' => $newer->id, 'product_id' => $productOne->id]);
        Wishlist::query()->create(['user_id' => $older->id, 'product_id' => $productTwo->id]);

        $service = app(CustomerDuplicateMergeService::class);
        $this->assertSame(1, $service->duplicateGroupCount());

        $result = $service->mergeNextBatch();

        $this->assertSame(1, $result['merged_groups']);
        $this->assertSame(1, $result['deleted_users']);
        $this->assertSame(1, $result['reassigned_orders']);
        $this->assertTrue($result['done']);

        $this->assertDatabaseMissing('users', ['id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id, 'phone' => '01712223344']);
        $this->assertSame($newer->id, $oldOrder->fresh()->user_id);
        $this->assertSame($newer->id, $newOrder->fresh()->user_id);
        $this->assertSame(2, Wishlist::query()->where('user_id', $newer->id)->count());
    }

    #[Test]
    public function merge_service_runs_until_finished(): void
    {
        $a = $this->customer('A', '01713334455');
        $b = $this->customer('B', '01713334456');
        DB::table('users')->where('id', $b->id)->update(['phone' => '1713334455']);
        $b->refresh();

        $this->orderFor($a, '2001');

        $merger = app(CustomerDuplicateMergeService::class);
        $mergedGroups = 0;
        $deletedUsers = 0;

        do {
            $result = $merger->mergeNextBatch();
            $mergedGroups += $result['merged_groups'];
            $deletedUsers += $result['deleted_users'];
        } while (! $result['done']);

        $this->assertSame(1, $mergedGroups);
        $this->assertSame(1, $deletedUsers);

        $keeperId = max($a->id, $b->id);
        $loserId = min($a->id, $b->id);
        $this->assertDatabaseMissing('users', ['id' => $loserId]);
        $this->assertDatabaseHas('users', ['id' => $keeperId]);
        $this->assertSame($keeperId, Order::query()->where('order_number', '2001')->value('user_id'));
    }
}
