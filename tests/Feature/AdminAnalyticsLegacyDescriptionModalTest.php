<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAnalyticsOrdersWithCosts;
use App\Models\Product;
use App\Models\User;
use App\Services\LegacyImport\LegacyDescriptionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalyticsLegacyDescriptionModalTest extends TestCase
{
    use RefreshDatabase;

    private string $legacySqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacySqlitePath = database_path('legacy-desc-modal-test.sqlite');
        if (is_file($this->legacySqlitePath)) {
            unlink($this->legacySqlitePath);
        }
        touch($this->legacySqlitePath);

        config([
            'database.connections.legacy' => [
                'driver' => 'sqlite',
                'database' => $this->legacySqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('legacy');
        DB::reconnect('legacy');

        Schema::connection('legacy')->dropIfExists('products');
        Schema::connection('legacy')->create('products', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->mediumText('product_detail')->nullable();
            $table->mediumText('product_detail_bn')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');
        if (isset($this->legacySqlitePath) && is_file($this->legacySqlitePath)) {
            unlink($this->legacySqlitePath);
        }

        parent::tearDown();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function modal_copies_descriptions_in_batches_of_one_hundred(): void
    {
        $this->actingAs($this->adminUser());

        for ($id = 1; $id <= 3; $id++) {
            Product::query()->create([
                'id' => $id,
                'name' => 'Product '.$id,
                'slug' => 'product-'.$id,
                'price' => 500,
                'purchase_price' => 200,
                'is_published' => true,
                'description' => null,
                'description_bn' => null,
            ]);

            DB::connection('legacy')->table('products')->insert([
                'id' => $id,
                'product_detail' => '<p>Detail '.$id.'</p><script>bad()</script>',
                'product_detail_bn' => '<p>বাংলা '.$id.'</p>',
            ]);
        }

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->assertSee('Copy legacy descriptions…')
            ->call('openLegacyDescriptionModal')
            ->assertSet('descModalOpen', true)
            ->assertSet('descTotal', 3)
            ->assertSee('Batches of '.LegacyDescriptionImporter::BATCH_SIZE)
            ->call('startLegacyDescriptionImport')
            ->assertSet('descDone', true)
            ->assertSet('descUpdated', 3)
            ->assertSet('descScanned', 3);

        $product = Product::query()->findOrFail(1);
        $this->assertStringContainsString('Detail 1', (string) $product->description);
        $this->assertStringNotContainsString('<script', strtolower((string) $product->description));
        $this->assertStringContainsString('বাংলা', (string) $product->description_bn);
    }

    #[Test]
    public function batch_api_advances_cursor_across_pages(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            Product::query()->create([
                'id' => $id,
                'name' => 'P'.$id,
                'slug' => 'p-'.$id,
                'price' => 100,
                'is_published' => true,
                'description' => null,
                'description_bn' => null,
            ]);
            DB::connection('legacy')->table('products')->insert([
                'id' => $id,
                'product_detail' => '<p>EN '.$id.'</p>',
                'product_detail_bn' => null,
            ]);
        }

        $importer = app(LegacyDescriptionImporter::class);

        $first = $importer->importNextBatch(0, 2);
        $this->assertSame(2, $first['scanned']);
        $this->assertSame(2, $first['updated']);
        $this->assertFalse($first['done']);

        $second = $importer->importNextBatch($first['next_after_id'], 2);
        $this->assertSame(2, $second['scanned']);
        $this->assertFalse($second['done']);

        $third = $importer->importNextBatch($second['next_after_id'], 2);
        $this->assertSame(1, $third['scanned']);
        $this->assertTrue($third['done']);
        $this->assertSame(5, Product::query()->whereNotNull('description')->count());
    }

    #[Test]
    public function force_overwrite_replaces_existing_descriptions(): void
    {
        $this->actingAs($this->adminUser());

        Product::query()->create([
            'id' => 20,
            'name' => 'Kept',
            'slug' => 'kept-20',
            'price' => 100,
            'is_published' => true,
            'description' => 'Old EN',
            'description_bn' => 'Old BN',
        ]);
        DB::connection('legacy')->table('products')->insert([
            'id' => 20,
            'product_detail' => '<p>New EN</p>',
            'product_detail_bn' => '<p>New BN</p>',
        ]);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openLegacyDescriptionModal')
            ->call('startLegacyDescriptionImport')
            ->assertSet('descUpdated', 0);

        $this->assertSame('Old EN', Product::query()->findOrFail(20)->description);

        Livewire::test(AdminAnalyticsOrdersWithCosts::class)
            ->call('openLegacyDescriptionModal')
            ->set('descForce', true)
            ->call('startLegacyDescriptionImport')
            ->assertSet('descUpdated', 1);

        $this->assertStringContainsString('New EN', (string) Product::query()->findOrFail(20)->description);
    }
}
