<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorefrontOwnershipMetaTest extends TestCase
{
    use RefreshDatabase;

    private const OWNERSHIP_TOKEN = 'f5ca7c844b1453e5440cee2998c4415fc1a028a8';

    #[Test]
    public function storefront_includes_ownership_verification_meta(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(
            '<meta name="'.self::OWNERSHIP_TOKEN.'" content="'.self::OWNERSHIP_TOKEN.'">',
            false,
        );
    }

    #[Test]
    public function admin_does_not_include_ownership_verification_meta(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee(
            'name="'.self::OWNERSHIP_TOKEN.'"',
            false,
        );
    }
}
