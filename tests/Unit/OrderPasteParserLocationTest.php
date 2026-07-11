<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\City;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\OrderPasteParser;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderPasteParserLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AddressLocationGuesser::clearCache();

        $dhaka = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        City::query()->create([
            'name' => 'Chattogram',
            'slug' => 'chattogram-chattogram',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        Area::query()->create([
            'city_id' => $dhaka->id,
            'name' => 'Wari',
            'slug' => 'dhaka-dhaka-wari',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);
    }

    public function test_pasted_chattogram_block_does_not_resolve_to_dhaka_wari(): void
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(false);

        $parser = new OrderPasteParser($gemini, app(AddressLocationGuesser::class));

        $parsed = $parser->parse(<<<'TXT'
Soma Chowdhury
449, Finlay zaran, Chatteswari road, chattogram.
01819610359
TXT);

        $this->assertSame('Soma Chowdhury', $parsed['name']);
        $this->assertSame('01819610359', $parsed['phone']);
        $this->assertNotNull($parsed['cityId']);
        $this->assertSame('Chattogram', City::query()->find($parsed['cityId'])?->name);
        $this->assertNull($parsed['areaId']);
        $this->assertSame('Chattogram', $parsed['location_hint']);
    }
}
