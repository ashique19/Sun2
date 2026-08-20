<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUsers;
use App\Models\User;
use App\Services\Admin\CustomerExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersExportTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

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
    public function export_redirects_to_download_route_with_token(): void
    {
        $this->actingAs($this->adminUser());
        $this->customer('Export Me', '01711111111');

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('exportFilteredCustomers')
            ->assertRedirect();
    }

    #[Test]
    public function export_livewire_flow_downloads_xlsx_via_session_token(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $this->customer('Export Me', '01711111111');

        $component = Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('exportFilteredCustomers');

        $component->assertRedirect();

        $redirect = $component->effects['redirect'] ?? null;
        $this->assertIsString($redirect);
        $this->assertMatchesRegularExpression('#/admin/users/customers/export/[0-9a-f-]{36}#i', $redirect);

        $response = $this->actingAs($admin)->get($redirect);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }

    #[Test]
    public function download_route_streams_xlsx_for_valid_token(): void
    {
        $admin = $this->adminUser();
        $this->customer('Export Me', '01711111111');

        $token = '11111111-1111-1111-1111-111111111111';
        app(CustomerExportService::class)->remember($token, [
            'user_id' => $admin->id,
            'search' => '',
            'cityFilter' => '',
            'cityNoneOnly' => false,
            'ordersMin' => '',
            'ordersMax' => '',
            'categoryId' => '',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.customers.export', ['token' => $token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }

    #[Test]
    public function download_route_streams_xlsx_when_only_cache_has_token(): void
    {
        $admin = $this->adminUser();
        $this->customer('Export Me', '01711111111');

        $token = '55555555-5555-5555-5555-555555555555';
        Cache::put(CustomerExportService::cacheKey($token), [
            'user_id' => $admin->id,
            'search' => '',
            'cityFilter' => '',
            'cityNoneOnly' => false,
            'ordersMin' => '',
            'ordersMax' => '',
            'categoryId' => '',
        ], now()->addMinutes(5));

        $response = $this->actingAs($admin)->get(route('admin.users.customers.export', ['token' => $token]));

        $response->assertOk();
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }

    #[Test]
    public function download_route_rejects_expired_token(): void
    {
        $admin = $this->adminUser();
        $token = '22222222-2222-2222-2222-222222222222';

        $this->actingAs($admin)
            ->get(route('admin.users.customers.export', ['token' => $token]))
            ->assertNotFound();
    }

    #[Test]
    public function download_route_rejects_token_for_other_admin(): void
    {
        $owner = $this->adminUser();
        $other = $this->adminUser();
        $token = '44444444-4444-4444-4444-444444444444';

        app(CustomerExportService::class)->remember($token, [
            'user_id' => $owner->id,
            'search' => '',
            'cityFilter' => '',
            'cityNoneOnly' => false,
            'ordersMin' => '',
            'ordersMax' => '',
            'categoryId' => '',
        ]);

        $this->actingAs($other)
            ->get(route('admin.users.customers.export', ['token' => $token]))
            ->assertNotFound();
    }

    #[Test]
    public function guest_cannot_download_export(): void
    {
        $token = '33333333-3333-3333-3333-333333333333';
        Cache::put(CustomerExportService::cacheKey($token), [
            'user_id' => 1,
            'search' => '',
            'cityFilter' => '',
            'cityNoneOnly' => false,
            'ordersMin' => '',
            'ordersMax' => '',
            'categoryId' => '',
        ], now()->addMinutes(5));

        $this->get(route('admin.users.customers.export', ['token' => $token]))
            ->assertRedirect(route('login'));
    }
}
