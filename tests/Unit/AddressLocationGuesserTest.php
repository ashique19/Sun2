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

    private AddressLocationGuesser $guesser;

    private City $dhaka;

    private City $chattogram;

    private Area $wari;

    private Area $uttara;

    private Area $bondor;

    private Area $kotwali;

    protected function setUp(): void
    {
        parent::setUp();

        AddressLocationGuesser::clearCache();
        $this->guesser = app(AddressLocationGuesser::class);

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
            'aliases' => ['Chittagong', 'CTG'],
        ]);

        $this->wari = Area::query()->create([
            'city_id' => $this->dhaka->id,
            'name' => 'Wari',
            'slug' => 'dhaka-dhaka-wari',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);
        $this->uttara = Area::query()->create([
            'city_id' => $this->dhaka->id,
            'name' => 'Uttara',
            'slug' => 'dhaka-dhaka-uttara',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);
        $this->bondor = Area::query()->create([
            'city_id' => $this->chattogram->id,
            'name' => 'Bondor',
            'slug' => 'chattogram-chattogram-bondor',
            'aliases' => ['বন্দর'],
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);
        $this->kotwali = Area::query()->create([
            'city_id' => $this->chattogram->id,
            'name' => 'Kotwali',
            'slug' => 'chattogram-chattogram-kotwali',
            'aliases' => ['chatteswari', 'Chatteswari Road'],
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);
    }

    public function test_it_matches_area_and_returns_its_city(): void
    {
        $result = $this->guesser->guess('House 12, Wari, Dhaka');

        $this->assertSame($this->dhaka->id, $result['city_id']);
        $this->assertSame($this->wari->id, $result['area_id']);
    }

    public function test_it_does_not_match_city_name_alone_without_area(): void
    {
        $this->assertNull($this->guesser->guess('Deliver to Chittagong please'));
    }

    public function test_chatteswari_alias_resolves_kotwali_not_dhaka_wari(): void
    {
        $result = $this->guesser->guess(
            'Soma Chowdhury 449, Finlay zaran, Chatteswari road, chattogram. 01819610359'
        );

        $this->assertNotNull($result);
        $this->assertSame($this->chattogram->id, $result['city_id']);
        $this->assertSame($this->kotwali->id, $result['area_id']);
        $this->assertNotSame($this->wari->id, $result['area_id']);
    }

    public function test_it_matches_uttara_sector_as_area_and_city(): void
    {
        $result = $this->guesser->guess('Flat B3, Uttara Sector 10, Dhaka');

        $this->assertSame($this->dhaka->id, $result['city_id']);
        $this->assertSame($this->uttara->id, $result['area_id']);
    }

    public function test_it_matches_area_alias_and_picks_city(): void
    {
        $result = $this->guesser->guess('Delivery to বন্দর, Chattogram');

        $this->assertSame($this->chattogram->id, $result['city_id']);
        $this->assertSame($this->bondor->id, $result['area_id']);
    }

    public function test_it_matches_hyphenated_area_slug_tokens(): void
    {
        Area::query()->create([
            'city_id' => $this->dhaka->id,
            'name' => 'Mirpur DOHS',
            'slug' => 'mirpur-dohs-dhaka',
            'is_active' => true,
            'delivery_charge_upto_5' => 80,
            'delivery_charge_over_5' => 150,
        ]);
        AddressLocationGuesser::clearCache();

        $result = $this->guesser->guess('House 5, Mirpur DOHS');

        $this->assertNotNull($result);
        $this->assertSame($this->dhaka->id, $result['city_id']);
        $this->assertSame('Mirpur DOHS', Area::query()->find($result['area_id'])?->name);
    }

    public function test_it_returns_null_when_no_area_matches(): void
    {
        $this->assertNull($this->guesser->guess('Unknown village somewhere'));
    }
}
