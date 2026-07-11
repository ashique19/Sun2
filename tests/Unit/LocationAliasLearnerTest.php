<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\City;
use App\Services\Locations\LocationAliasLearner;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationAliasLearnerTest extends TestCase
{
    use RefreshDatabase;

    private City $chattogram;

    private Area $kotwali;

    protected function setUp(): void
    {
        parent::setUp();

        AddressLocationGuesser::clearCache();

        $this->chattogram = City::query()->create([
            'name' => 'Chattogram',
            'slug' => 'chattogram-chattogram',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        $this->kotwali = Area::query()->create([
            'city_id' => $this->chattogram->id,
            'name' => 'Kotwali',
            'slug' => 'chattogram-chattogram-kotwali',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);
    }

    public function test_learns_aliases_when_admin_selects_area_guesser_missed(): void
    {
        $learner = app(LocationAliasLearner::class);

        $learned = $learner->learnFromCorrection(
            address: '449, Finlay zaran, Chatteswari road, chattogram.',
            selectedCityId: $this->chattogram->id,
            selectedAreaId: $this->kotwali->id,
            guessedCityId: $this->chattogram->id,
            guessedAreaId: null,
        );

        $this->assertNotEmpty($learned['area']);
        $this->assertContains('chatteswari', array_map('mb_strtolower', $learned['area']));

        $this->kotwali->refresh();
        $this->assertTrue(collect($this->kotwali->aliasList())
            ->map(fn ($alias) => mb_strtolower($alias))
            ->contains('chatteswari'));

        AddressLocationGuesser::clearCache();

        $guess = app(AddressLocationGuesser::class)->guess(
            '449, Finlay zaran, Chatteswari road, chattogram.',
        );

        $this->assertSame($this->kotwali->id, $guess['area_id'] ?? null);
    }

    public function test_does_not_learn_when_guess_already_matched_area(): void
    {
        $this->kotwali->addAliases(['chatteswari']);
        AddressLocationGuesser::clearCache();

        $learned = app(LocationAliasLearner::class)->learnFromCorrection(
            address: '449, Finlay zaran, Chatteswari road, chattogram.',
            selectedCityId: $this->chattogram->id,
            selectedAreaId: $this->kotwali->id,
            guessedCityId: $this->chattogram->id,
            guessedAreaId: $this->kotwali->id,
        );

        $this->assertSame([], $learned['area']);
    }
}
