<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleAnalyticsTagTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function storefront_includes_google_analytics_gtag(): void
    {
        config(['services.google.analytics_id' => 'G-0C9GKKCSKJ']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('https://www.googletagmanager.com/gtag/js?id=G-0C9GKKCSKJ', false);
        $response->assertSee("gtag('config', 'G-0C9GKKCSKJ')", false);
        $response->assertSee('livewire:navigated', false);
    }

    #[Test]
    public function storefront_omits_google_analytics_when_id_is_empty(): void
    {
        config(['services.google.analytics_id' => '']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('googletagmanager.com/gtag/js', false);
        $response->assertDontSee("gtag('config'", false);
    }

    #[Test]
    public function admin_does_not_include_google_analytics(): void
    {
        config(['services.google.analytics_id' => 'G-0C9GKKCSKJ']);

        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('googletagmanager.com/gtag/js', false);
    }
}
