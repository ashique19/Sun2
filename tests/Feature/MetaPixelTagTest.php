<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MetaPixelTagTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function storefront_includes_meta_pixel(): void
    {
        config(['services.meta.pixel_id' => '952667959398529']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('https://connect.facebook.net/en_US/fbevents.js', false);
        $response->assertSee("fbq('init', '952667959398529')", false);
        $response->assertSee("fbq('track', 'PageView')", false);
        $response->assertSee('https://www.facebook.com/tr?id=952667959398529&ev=PageView&noscript=1', false);
        $response->assertSee('livewire:navigated', false);
    }

    #[Test]
    public function storefront_omits_meta_pixel_when_id_is_empty(): void
    {
        config(['services.meta.pixel_id' => '']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('connect.facebook.net/en_US/fbevents.js', false);
        $response->assertDontSee("fbq('init'", false);
    }

    #[Test]
    public function admin_does_not_include_meta_pixel(): void
    {
        config(['services.meta.pixel_id' => '952667959398529']);

        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('connect.facebook.net/en_US/fbevents.js', false);
        $response->assertDontSee("fbq('init'", false);
    }
}
