<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Admin\CustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerLookupService $customers;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('customers');
        $this->customers = app(CustomerLookupService::class);
    }

    public function test_it_creates_customer_for_new_phone(): void
    {
        $user = $this->customers->findOrCreateCustomer('01712345678', 'Ada Lovelace');

        $this->assertNotNull($user);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue($user->hasRole('customers'));
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_reuses_existing_customer_by_phone(): void
    {
        $first = $this->customers->findOrCreateCustomer('01712345678', 'First Name');
        $second = $this->customers->findOrCreateCustomer('01712345678', 'Different Name');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('First Name', $second->fresh()->name);
    }

    public function test_it_matches_phone_variants(): void
    {
        $first = $this->customers->findOrCreateCustomer('01712345678', 'Ada');
        $second = $this->customers->findOrCreateCustomer('+8801712345678', 'Ada Again');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::query()->count());
    }
}
