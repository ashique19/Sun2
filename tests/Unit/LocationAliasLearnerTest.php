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

    private LocationAliasLearner $learner;

    private City $chittagong;

    private Area $bondor;

    protected function setUp(): void
    {
        parent::setUp();

        AddressLocationGuesser::clearCache();
        $this->learner = app(LocationAliasLearner::class);

        $this->chittagong = City::query()->create([
            'name' => 'Chittagong',
            'slug' => 'chittagong-chittagong',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);
        $this->bondor = Area::query()->create([
            'city_id' => $this->chittagong->id,
            'name' => 'Bondor',
            'slug' => 'chittagong-chittagong-bondor',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);
    }

    public function test_it_suggests_bangla_alias_for_selected_area(): void
    {
        $suggestion = $this->learner->suggestAlias(
            "Delivery to বন্দর area\nChittagong",
            $this->bondor->id,
        );

        $this->assertNotNull($suggestion);
        $this->assertSame($this->bondor->id, $suggestion['area_id']);
        $this->assertSame('বন্দর', $suggestion['alias']);
        $this->assertSame('Add বন্দর to Chittagong > Bondor alias?', $suggestion['prompt']);
    }

    public function test_it_prefers_pasted_area_hint_over_address_tokens(): void
    {
        $suggestion = $this->learner->suggestAlias(
            address: 'House 12, Agrabad road',
            selectedAreaId: $this->bondor->id,
            areaHint: 'Bondhor',
        );

        $this->assertNotNull($suggestion);
        $this->assertSame('Bondhor', $suggestion['alias']);
        $this->assertSame('Add Bondhor to Chittagong > Bondor alias?', $suggestion['prompt']);
    }

    public function test_it_suggests_from_area_hint_when_address_empty(): void
    {
        $suggestion = $this->learner->suggestAlias(
            address: '',
            selectedAreaId: $this->bondor->id,
            areaHint: 'Bondhor',
        );

        $this->assertNotNull($suggestion);
        $this->assertSame('Bondhor', $suggestion['alias']);
        $this->assertSame('Add Bondhor to Chittagong > Bondor alias?', $suggestion['prompt']);
    }

    public function test_it_suggests_mismatched_spelling_for_similar_area_name(): void
    {
        $bandar = Area::query()->create([
            'city_id' => $this->chittagong->id,
            'name' => 'Bandar',
            'slug' => 'chittagong-chittagong-bandar',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);

        // "Bondor" conflicts with sibling area name Bondor — skip it.
        $this->assertNull($this->learner->suggestAlias(
            address: '',
            selectedAreaId: $bandar->id,
            areaHint: 'Bondor',
        ));

        $suggestion = $this->learner->suggestAlias(
            address: '',
            selectedAreaId: $bandar->id,
            areaHint: 'বন্দর',
        );

        $this->assertNotNull($suggestion);
        $this->assertSame('বন্দর', $suggestion['alias']);
    }

    public function test_it_does_not_suggest_when_auto_picked_area_matches(): void
    {
        $this->assertNull($this->learner->suggestAlias(
            'Delivery to বন্দর',
            $this->bondor->id,
            $this->bondor->id,
        ));
    }

    public function test_it_does_not_suggest_when_phrase_already_known(): void
    {
        $this->bondor->update(['aliases' => ['বন্দর']]);

        $this->assertNull($this->learner->suggestAlias(
            'Delivery to বন্দর',
            $this->bondor->id,
        ));
    }

    public function test_confirm_alias_persists_and_is_idempotent(): void
    {
        $added = $this->learner->confirmAlias($this->bondor->id, 'বন্দর');
        $this->assertSame(['বন্দর'], $added);
        $this->bondor->refresh();
        $this->assertContains('বন্দর', $this->bondor->aliasList());

        $this->assertSame([], $this->learner->confirmAlias($this->bondor->id, 'বন্দর'));
    }

    public function test_it_skips_short_or_numeric_tokens(): void
    {
        $this->assertNull($this->learner->suggestAlias(
            'House 12 Road 5',
            $this->bondor->id,
        ));
    }
}
