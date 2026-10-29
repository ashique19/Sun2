<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminCustomerShow;
use App\Livewire\Admin\AdminUsers;
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

    #[Test]
    public function merge_includes_customers_without_the_customers_role(): void
    {
        $older = User::factory()->create([
            'name' => 'No Role Older',
            'phone' => '01714445566',
        ]);
        $newer = User::factory()->create([
            'name' => 'No Role Newer',
            'phone' => '01714445567',
        ]);
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801714445566']);
        $newer->refresh();

        $this->orderFor($older, '3001');

        $result = app(CustomerDuplicateMergeService::class)->mergeNextBatch();

        $this->assertSame(1, $result['merged_groups']);
        $this->assertDatabaseMissing('users', ['id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id]);
        $this->assertSame($newer->id, Order::query()->where('order_number', '3001')->value('user_id'));
    }

    #[Test]
    public function merge_groups_legacy_suffixed_phones_onto_the_latest_user(): void
    {
        $oldest = $this->customer('কাকলি চাকমা', '01866609878-22737');
        $middle = $this->customer('কাকলি চাকমা', '01866609878-24973');
        $newest = $this->customer('কাকলি চাকমা', '01866609878-26841');
        $this->orderFor($oldest, '6001');
        $this->orderFor($middle, '6002');

        $service = app(CustomerDuplicateMergeService::class);
        $this->assertSame(1, $service->duplicateGroupCount());

        $result = $service->mergeNextBatch();

        $this->assertSame(1, $result['merged_groups']);
        $this->assertSame(2, $result['deleted_users']);
        $this->assertSame(2, $result['reassigned_orders']);
        $this->assertTrue($result['done']);

        $this->assertDatabaseMissing('users', ['id' => $oldest->id]);
        $this->assertDatabaseMissing('users', ['id' => $middle->id]);
        $this->assertDatabaseHas('users', ['id' => $newest->id, 'phone' => '01866609878']);
        $this->assertSame($newest->id, Order::query()->where('order_number', '6001')->value('user_id'));
        $this->assertSame($newest->id, Order::query()->where('order_number', '6002')->value('user_id'));
    }

    #[Test]
    public function customers_page_can_preview_and_merge_duplicate_phones(): void
    {
        $this->actingAs($this->adminUser());

        $older = $this->customer('Older Twin', '01718889900');
        $newer = $this->customer('Newer Twin', '01718889901');
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801718889900']);
        $this->orderFor($older, '5001');

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->assertSee('Merge duplicate phones')
            ->call('openDuplicateMergeModal')
            ->assertSet('duplicateMergeModalOpen', true)
            ->assertSet('duplicateMergePreview.groups', 1)
            ->assertSet('duplicateMergePreview.users_to_remove', 1)
            ->assertSet('duplicateMergePreview.orders_to_move', 1)
            ->assertSee('Merge now')
            ->call('mergeDuplicatePhones')
            ->assertSet('duplicateMergeRunning', false)
            ->assertSet('duplicateMergePreview.done', true)
            ->assertSee('Merged 1 phone group');

        $this->assertDatabaseMissing('users', ['id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id]);
        $this->assertSame($newer->id, Order::query()->where('order_number', '5001')->value('user_id'));
    }

    #[Test]
    public function merge_caps_how_many_extra_users_one_batch_deletes(): void
    {
        $users = [];

        for ($i = 0; $i < 10; $i++) {
            $users[] = $this->customer('Kakoli '.$i, '01866609878-'.(20000 + $i));
        }

        $keeper = $users[array_key_last($users)];
        $service = app(CustomerDuplicateMergeService::class);

        $result = $service->mergeNextBatch(limit: 1, maxLosers: 3);

        $this->assertSame(3, $result['deleted_users']);
        $this->assertSame(0, $result['merged_groups']);
        $this->assertFalse($result['done']);
        $this->assertSame(1, $service->duplicateGroupCount());
        $this->assertDatabaseHas('users', ['id' => $keeper->id]);
        $this->assertSame(7, User::query()->whereIn('id', collect($users)->pluck('id'))->count());
    }

    #[Test]
    public function merge_detaches_sessions_so_extra_users_can_be_deleted(): void
    {
        $older = $this->customer('Older Session', '01719990011');
        $newer = $this->customer('Newer Session', '01719990012');
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801719990011']);

        DB::table('sessions')->insert([
            'id' => 'merge-session-'.$older->id,
            'user_id' => $older->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $result = app(CustomerDuplicateMergeService::class)->mergeNextBatch();

        $this->assertSame(1, $result['deleted_users']);
        $this->assertDatabaseMissing('users', ['id' => $older->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id, 'phone' => '01719990011']);
    }

    #[Test]
    public function merge_skips_a_user_that_cannot_be_deleted_and_continues(): void
    {
        $oldest = $this->customer('Keep Going', '01717778899');
        $blocked = $this->customer('Boom', '01717778890');
        $newest = $this->customer('Newest', '01717778891');
        DB::table('users')->where('id', $blocked->id)->update(['phone' => '01717778899-'.$blocked->id]);
        DB::table('users')->where('id', $newest->id)->update(['phone' => '8801717778899']);
        $this->orderFor($oldest, '7001');

        User::deleting(function (User $user): void {
            if ($user->name === 'Boom') {
                throw new \RuntimeException('cannot delete boom');
            }
        });

        $result = app(CustomerDuplicateMergeService::class)->mergeNextBatch();

        $this->assertContains($blocked->id, $result['skipped_user_ids']);
        $this->assertSame(1, $result['deleted_users']);
        $this->assertSame(0, $result['merged_groups']);
        $this->assertDatabaseMissing('users', ['id' => $oldest->id]);
        $this->assertDatabaseHas('users', ['id' => $blocked->id]);
        $this->assertDatabaseHas('users', ['id' => $newest->id]);
        $this->assertSame($newest->id, Order::query()->where('order_number', '7001')->value('user_id'));
    }

    #[Test]
    public function preview_does_not_list_every_id_in_a_large_group(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->customer('Crowd '.$i, '01861112233-'.(30000 + $i));
        }

        $preview = app(CustomerDuplicateMergeService::class)->preview();

        $this->assertSame(1, $preview['groups']);
        $this->assertSame(5, $preview['users_to_remove']);
        $this->assertCount(1, $preview['samples']);
        $this->assertStringContainsString('+2 more', $preview['samples'][0]);
    }

    #[Test]
    public function customers_page_merges_large_sets_across_poll_ticks(): void
    {
        $this->actingAs($this->adminUser());

        $pairs = CustomerDuplicateMergeService::UI_BATCH_SIZE + 1;
        $olders = [];
        $newers = [];

        for ($i = 0; $i < $pairs; $i++) {
            $display = sprintf('01710%06d', $i);
            $olders[] = $this->customer('Older '.$i, $display);
            $newer = $this->customer('Newer '.$i, sprintf('01711%06d', $i));
            DB::table('users')->where('id', $newer->id)->update(['phone' => '880'.substr($display, 1)]);
            $newers[] = $newer->fresh();
            $this->orderFor($olders[$i], '8'.sprintf('%03d', $i));
        }

        $component = Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('openDuplicateMergeModal')
            ->assertSet('duplicateMergePreview.groups', $pairs)
            ->call('mergeDuplicatePhones')
            ->assertSet('duplicateMergeRunning', true)
            ->assertSet('duplicateMergePreview.done', false)
            ->assertSeeHtml('wire:poll.1s="tickDuplicateMerge"');

        $this->assertTrue(
            User::query()->whereIn('id', collect($olders)->pluck('id'))->exists(),
            'The first Livewire request must leave some duplicates for later ticks.',
        );

        $guard = 0;

        while ($component->get('duplicateMergeRunning') && $guard < 20) {
            $component->call('tickDuplicateMerge');
            $guard++;
        }

        $component
            ->assertSet('duplicateMergeRunning', false)
            ->assertSet('duplicateMergePreview.done', true)
            ->assertSee('Merged '.$pairs.' phone group');

        foreach ($olders as $older) {
            $this->assertDatabaseMissing('users', ['id' => $older->id]);
        }

        foreach ($newers as $newer) {
            $this->assertDatabaseHas('users', ['id' => $newer->id]);
        }
    }
}
