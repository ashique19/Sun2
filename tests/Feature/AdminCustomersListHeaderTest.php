<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersListHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_customers_header_is_compact_without_merge_or_segment_pills(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->assertDontSee('Merge duplicate phones')
            ->assertDontSeeHtml('>Create Customer</a>')
            ->assertSeeHtml('aria-label="Create Customer"')
            ->assertSeeHtml('id="admin-users-segment"')
            ->assertSeeHtml('>Customers</option>')
            ->assertSeeHtml('>Moderators</option>')
            ->assertSeeHtml('>Resellers</option>')
            ->assertSeeHtml('>Admins</option>')
            ->call('switchSegment', 'moderators')
            ->assertSet('segment', 'moderators')
            ->assertSee('Moderators')
            ->assertSeeHtml('aria-label="Create Moderator"');
    }
}
