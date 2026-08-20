<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUsers;
use App\Models\Order;
use App\Models\User;
use App\Support\SimpleXlsxExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

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
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
        $user->assignRole('customers');

        return $user;
    }

    public function test_exports_filtered_customers_as_xlsx(): void
    {
        $admin = $this->adminUser();
        $dhaka = $this->customer('Export Dhaka', '01760000001');
        $ctg = $this->customer('Export CTG', '01760000002');

        Order::query()->create([
            'order_number' => 'EX-1',
            'user_id' => $dhaka->id,
            'name' => $dhaka->name,
            'phone' => $dhaka->phone,
            'address' => 'House 1',
            'city' => 'Dhaka',
            'subtotal' => 100,
            'delivery_charge' => 0,
            'discount' => 0,
            'total' => 100,
            'cod_amount' => 100,
            'due_amount' => 100,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->set('cityFilter', 'Dhaka')
            ->call('exportFilteredCustomers')
            ->assertFileDownloaded();

        $download = data_get($component->effects, 'download');
        $this->assertIsArray($download);
        $this->assertStringEndsWith('.xlsx', (string) data_get($download, 'name'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            data_get($download, 'contentType'),
        );

        $content = base64_decode((string) data_get($download, 'content'), true);
        $this->assertNotFalse($content);
        $this->assertStringStartsWith('PK', $content);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-dl');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $content);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertIsString($sheet);
        $this->assertStringContainsString('Export Dhaka', $sheet);
        $this->assertStringNotContainsString('Export CTG', $sheet);
    }

    public function test_simple_xlsx_exporter_builds_valid_zip(): void
    {
        $binary = app(SimpleXlsxExporter::class)->build(
            ['Name', 'Phone'],
            [['Alice', '01700000001']],
        );

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertIsString($sheet);
        $this->assertStringContainsString('Alice', $sheet);
        $this->assertStringContainsString('01700000001', $sheet);
    }

    public function test_customers_search_row_hides_manual_city_and_orders_inputs(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->assertDontSee('Lifetime orders from')
            ->assertDontSeeHtml('wire:model.live.debounce.300ms="cityFilter"')
            ->assertDontSeeHtml('wire:model.live.debounce.300ms="ordersMin"')
            ->assertSeeHtml('aria-label="Export filtered customers to Excel"')
            ->assertSeeHtml('placeholder="Search name, phone, email…"');
    }
}
