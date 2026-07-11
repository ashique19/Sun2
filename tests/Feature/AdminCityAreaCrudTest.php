<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAreaEdit;
use App\Livewire\Admin\AdminAreas;
use App\Livewire\Admin\AdminCities;
use App\Livewire\Admin\AdminCityEdit;
use App\Models\Area;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCityAreaCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_can_create_update_and_delete_city(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminCityEdit::class)
            ->set('name', 'Gazipur')
            ->set('slug', 'gazipur')
            ->set('division', 'Dhaka')
            ->set('is_dhaka', false)
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.cities.edit', City::query()->first()));

        $city = City::query()->first();
        $this->assertNotNull($city);
        $this->assertSame('Gazipur', $city->name);
        $this->assertSame('gazipur', $city->slug);
        $this->assertSame('Dhaka', $city->division);

        Livewire::test(AdminCityEdit::class, ['city' => $city])
            ->set('name', 'Gazipur City')
            ->set('is_dhaka', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'City saved.');

        $city->refresh();
        $this->assertSame('Gazipur City', $city->name);
        $this->assertTrue($city->is_dhaka);

        Livewire::test(AdminCities::class)
            ->call('delete', $city->id)
            ->assertSet('message', 'City “Gazipur City” deleted.');

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_admin_can_create_update_and_delete_area(): void
    {
        $this->actingAs($this->adminUser());

        $city = City::query()->create([
            'name' => 'Dhaka',
            'slug' => 'dhaka-dhaka',
            'division' => 'Dhaka',
            'is_dhaka' => true,
            'is_active' => true,
        ]);

        Livewire::test(AdminAreaEdit::class, ['city' => $city->id])
            ->assertSet('cityId', $city->id)
            ->set('name', 'Mirpur')
            ->set('slug', 'mirpur')
            ->set('police_station', 'Mirpur')
            ->set('unit_type', 'thana')
            ->set('delivery_charge_upto_5', '80')
            ->set('delivery_charge_over_5', '150')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.areas.edit', Area::query()->first()));

        $area = Area::query()->first();
        $this->assertNotNull($area);
        $this->assertSame('Mirpur', $area->name);
        $this->assertSame($city->id, $area->city_id);
        $this->assertSame(80, $area->delivery_charge_upto_5);
        $this->assertSame(150, $area->delivery_charge_over_5);

        Livewire::test(AdminAreaEdit::class, ['area' => $area])
            ->set('delivery_charge_upto_5', '90')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Area saved.');

        $area->refresh();
        $this->assertSame(90, $area->delivery_charge_upto_5);

        Livewire::test(AdminAreas::class)
            ->call('delete', $area->id)
            ->assertSet('message', 'Area “Mirpur” deleted.');

        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }

    public function test_deleting_city_cascades_areas(): void
    {
        $this->actingAs($this->adminUser());

        $city = City::query()->create([
            'name' => 'Chattogram',
            'slug' => 'chattogram',
            'division' => 'Chattogram',
            'is_dhaka' => false,
            'is_active' => true,
        ]);

        $area = Area::query()->create([
            'city_id' => $city->id,
            'name' => 'Agrabad',
            'slug' => 'agrabad',
            'is_active' => true,
            'delivery_charge_upto_5' => 120,
            'delivery_charge_over_5' => 200,
        ]);

        Livewire::test(AdminCityEdit::class, ['city' => $city])
            ->call('delete')
            ->assertRedirect(route('admin.cities'));

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }

    public function test_guest_cannot_access_city_admin_pages(): void
    {
        $this->get(route('admin.cities'))->assertRedirect(route('login'));
        $this->get(route('admin.areas'))->assertRedirect(route('login'));
    }
}
