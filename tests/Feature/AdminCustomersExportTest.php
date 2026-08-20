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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
    public function download_route_streams_xlsx_for_valid_token(): void
    {
        $admin = $this->adminUser();
        $this->customer('Export Me', '01711111111');

        $token = '11111111-1111-1111-1111-111111111111';
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
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('content-disposition')
        );

        $base = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);
        $content = file_get_contents($base->getFile()->getPathname());
        $this->assertNotFalse($content);
        $this->assertSame('PK', substr($content, 0, 2));
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

        Cache::put(CustomerExportService::cacheKey($token), [
            'user_id' => $owner->id,
            'search' => '',
            'cityFilter' => '',
            'cityNoneOnly' => false,
            'ordersMin' => '',
            'ordersMax' => '',
            'categoryId' => '',
        ], now()->addMinutes(5));

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
