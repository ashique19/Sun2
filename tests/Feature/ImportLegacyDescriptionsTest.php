<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\LegacyImport\LegacyDescriptionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private string $legacySqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacySqlitePath = database_path('legacy-descriptions-test.sqlite');
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

        $this->createLegacyProductsTable();
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');
        if (isset($this->legacySqlitePath) && is_file($this->legacySqlitePath)) {
            unlink($this->legacySqlitePath);
        }

        parent::tearDown();
    }

    public function test_sanitize_strips_scripts_and_keeps_basic_markup(): void
    {
        $importer = app(LegacyDescriptionImporter::class);

        $clean = $importer->sanitizeHtml(
            '<p>Hello</p><script>alert(1)</script><p onclick="x">World</p><a href="javascript:alert(1)">x</a>'
        );

        $this->assertStringContainsString('<p>Hello</p>', $clean);
        $this->assertStringContainsString('World', $clean);
        $this->assertStringNotContainsString('<script', strtolower($clean));
        $this->assertStringNotContainsString('onclick', strtolower($clean));
        $this->assertStringNotContainsString('javascript:', strtolower($clean));
    }

    public function test_imports_descriptions_into_empty_products(): void
    {
        Product::query()->create([
            'id' => 10,
            'name' => 'Necklace',
            'slug' => 'necklace-10',
            'price' => 1000,
            'purchase_price' => 400,
            'is_published' => true,
            'description' => null,
            'description_bn' => null,
        ]);

        DB::connection('legacy')->table('products')->insert([
            'id' => 10,
            'product_detail' => '<p>English detail</p><script>bad()</script>',
            'product_detail_bn' => '<p>বাংলা বর্ণনা</p>',
        ]);

        $exit = Artisan::call('import:legacy-descriptions');

        $this->assertSame(0, $exit);

        $product = Product::query()->findOrFail(10);
        $this->assertStringContainsString('English detail', (string) $product->description);
        $this->assertStringNotContainsString('<script', strtolower((string) $product->description));
        $this->assertStringContainsString('বাংলা', (string) $product->description_bn);
    }

    public function test_does_not_overwrite_existing_unless_force(): void
    {
        Product::query()->create([
            'id' => 11,
            'name' => 'Ring',
            'slug' => 'ring-11',
            'price' => 500,
            'is_published' => true,
            'description' => 'Keep me',
            'description_bn' => 'রাখো',
        ]);

        DB::connection('legacy')->table('products')->insert([
            'id' => 11,
            'product_detail' => '<p>New EN</p>',
            'product_detail_bn' => '<p>New BN</p>',
        ]);

        Artisan::call('import:legacy-descriptions');
        $this->assertSame('Keep me', Product::query()->findOrFail(11)->description);

        Artisan::call('import:legacy-descriptions', ['--force' => true]);
        $this->assertStringContainsString('New EN', (string) Product::query()->findOrFail(11)->description);
    }

    private function createLegacyProductsTable(): void
    {
        Schema::connection('legacy')->dropIfExists('products');
        Schema::connection('legacy')->create('products', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->mediumText('product_detail')->nullable();
            $table->mediumText('product_detail_bn')->nullable();
        });
    }
}
