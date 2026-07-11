<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressLocationGuesserTest extends TestCase
{
    use RefreshDatabase;

    private City $dhaka;

    private City $chattogram;

    private Area $wari;

    private Area $kotwaliChattogram;

    protected function setUp(): void
    {
        parent::setUp();

        AddressLocationGuesser::clearCache();

        $this->dhaka = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        $this->chattogram = City::query()->create([
            'name' => 'Chattogram',
            'slug' => 'chattogram-chattogram',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        $this->wari = Area::query()->create([
            'city_id' => $this->dhaka->id,
            'name' => 'Wari',
            'slug' => 'dhaka-dhaka-wari',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);

        $this->kotwaliChattogram = Area::query()->create([
            'city_id' => $this->chattogram->id,
            'name' => 'Kotwali',
            'slug' => 'chattogram-chattogram-kotwali',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);
    }

    public function test_chatteswari_chattogram_address_does_not_match_dhaka_wari(): void
    {
        $guess = app(AddressLocationGuesser::class)->guess(
            '449, Finlay zaran, Chatteswari road, chattogram.',
        );

        $this->assertNotNull($guess);
        $this->assertSame($this->chattogram->id, $guess['city_id']);
        $this->assertNull($guess['area_id']);
        $this->assertSame('Chattogram', $guess['label']);
    }

    public function test_chittagong_alias_resolves_to_chattogram_city(): void
    {
        $guess = app(AddressLocationGuesser::class)->guess(
            'Chatteswari road, Chittagong',
        );

        $this->assertNotNull($guess);
        $this->assertSame($this->chattogram->id, $guess['city_id']);
        $this->assertNull($guess['area_id']);
    }

    public function test_standalone_wari_still_matches_dhaka_area(): void
    {
        $guess = app(AddressLocationGuesser::class)->guess('12 Rankin Street, Wari, Dhaka');

        $this->assertNotNull($guess);
        $this->assertSame($this->dhaka->id, $guess['city_id']);
        $this->assertSame($this->wari->id, $guess['area_id']);
    }

    public function test_area_match_is_scoped_to_detected_city(): void
    {
        $guess = app(AddressLocationGuesser::class)->guess('Near Kotwali, Chattogram');

        $this->assertNotNull($guess);
        $this->assertSame($this->chattogram->id, $guess['city_id']);
        $this->assertSame($this->kotwaliChattogram->id, $guess['area_id']);
    }
}
