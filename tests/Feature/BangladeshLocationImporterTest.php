<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\City;
use App\Services\Locations\BangladeshLocationImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BangladeshLocationImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function import_is_idempotent_and_loads_full_dataset(): void
    {
        $importer = app(BangladeshLocationImporter::class);

        $first = $importer->import();
        $second = $importer->import();

        $this->assertSame(64, $first['cities']);
        $this->assertSame(603, $first['areas']);
        $this->assertSame($first, $second);
        $this->assertSame(64, City::query()->count());
        $this->assertSame(603, Area::query()->count());
        $this->assertTrue(City::query()->where('slug', 'dhaka-dhaka')->where('is_dhaka', true)->exists());
        $this->assertTrue(Area::query()->where('slug', 'like', 'dhaka-dhaka-%')->exists());
    }
}
