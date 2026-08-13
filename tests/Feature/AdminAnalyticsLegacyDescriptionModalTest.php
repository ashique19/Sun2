<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\LegacyImport\LegacyDescriptionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
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
}
