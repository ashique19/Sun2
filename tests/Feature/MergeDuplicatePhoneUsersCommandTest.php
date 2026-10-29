<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MergeDuplicatePhoneUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $name, string $phone): User
    {
        Role::findOrCreate('customers');

        $user = User::factory()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
        $user->assignRole('customers');

        return $user;
    }

    #[Test]
    public function dry_run_lists_duplicates_without_deleting(): void
    {
        $older = $this->customer('Older', '01715556677');
        $newer = $this->customer('Newer', '01715556678');
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801715556677']);

        $exit = Artisan::call('customers:merge-duplicate-phones', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', Artisan::output());
        $this->assertDatabaseHas('users', ['id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id]);
    }

    #[Test]
    public function command_merges_onto_latest_user_and_moves_orders(): void
    {
        $older = $this->customer('Older', '01716667788');
        $newer = $this->customer('Newer', '01716667789');
        DB::table('users')->where('id', $newer->id)->update(['phone' => '8801716667788']);

        Order::query()->create([
            'order_number' => '4001',
            'user_id' => $older->id,
            'name' => $older->name,
            'phone' => $older->phone,
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

        $exit = Artisan::call('customers:merge-duplicate-phones');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Duplicate phone merge complete', Artisan::output());
        $this->assertDatabaseMissing('users', ['id' => $older->id]);
        $this->assertDatabaseHas('users', ['id' => $newer->id]);
        $this->assertSame($newer->id, Order::query()->where('order_number', '4001')->value('user_id'));
    }
}
