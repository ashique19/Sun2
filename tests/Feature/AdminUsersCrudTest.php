<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminUserEdit;
use App\Livewire\Admin\AdminUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUsersCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(array $attributes = []): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create($attributes);
        $user->assignRole('admin');

        return $user;
    }

    private function moderatorUser(): User
    {
        Role::findOrCreate('moderator');

        $user = User::factory()->create();
        $user->assignRole('moderator');

        return $user;
    }

    public function test_admin_can_create_update_and_delete_admin_user(): void
    {
        $actor = $this->adminUser(['phone' => '01711111111']);

        $this->actingAs($actor);

        Livewire::test(AdminUserEdit::class)
            ->set('name', 'Second Admin')
            ->set('phone', '01712222222')
            ->set('email', 'second@example.com')
            ->set('role', 'admin')
            ->set('password', 'SecretPass1!')
            ->set('password_confirmation', 'SecretPass1!')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.edit', User::query()->where('phone', '01712222222')->first()));

        $created = User::query()->where('phone', '01712222222')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('admin'));

        Livewire::test(AdminUserEdit::class, ['user' => $created])
            ->set('name', 'Updated Admin')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'User saved.');

        $created->refresh();
        $this->assertSame('Updated Admin', $created->name);

        Livewire::test(AdminUsers::class, ['segment' => 'admins'])
            ->assertSee('Updated Admin')
            ->call('delete', $created->id)
            ->assertSet('message', 'User deleted.');

        $this->assertDatabaseMissing('users', ['id' => $created->id]);
    }

    public function test_admins_segment_lists_only_admin_role_users(): void
    {
        Role::findOrCreate('customers');
        Role::findOrCreate('dev');

        $actor = $this->adminUser();
        $otherAdmin = $this->adminUser(['phone' => '01713333333', 'name' => 'Listed Admin']);

        $customer = User::factory()->create(['name' => 'Store Customer']);
        $customer->assignRole('customers');

        $dev = User::factory()->create(['name' => 'Dev User']);
        $dev->assignRole('dev');

        $this->actingAs($actor);

        Livewire::test(AdminUsers::class, ['segment' => 'admins'])
            ->assertSee('Listed Admin')
            ->assertSee($actor->name)
            ->assertDontSee('Store Customer')
            ->assertDontSee('Dev User');
    }

    public function test_cannot_delete_the_only_admin_account(): void
    {
        Role::findOrCreate('dev');

        $onlyAdmin = $this->adminUser();
        $dev = User::factory()->create();
        $dev->assignRole('dev');

        $this->actingAs($onlyAdmin);

        Livewire::test(AdminUserEdit::class, ['user' => $onlyAdmin])
            ->call('delete')
            ->assertSet('error', 'You cannot delete your own account.');

        $this->actingAs($dev);

        Livewire::test(AdminUsers::class, ['segment' => 'admins'])
            ->call('delete', $onlyAdmin->id)
            ->assertSet('error', 'Cannot delete the only admin account.');

        $this->assertDatabaseHas('users', ['id' => $onlyAdmin->id]);
    }

    public function test_cannot_demote_or_deactivate_the_only_admin_account(): void
    {
        $actor = $this->adminUser();

        $this->actingAs($actor);

        Livewire::test(AdminUserEdit::class, ['user' => $actor])
            ->set('role', 'customers')
            ->call('save')
            ->assertHasErrors(['role']);

        Livewire::test(AdminUserEdit::class, ['user' => $actor])
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors(['is_active']);
    }

    public function test_moderator_cannot_access_admin_users_routes(): void
    {
        $this->actingAs($this->moderatorUser());

        $this->get(route('admin.users.admins'))->assertForbidden();
        $this->get(route('admin.users.create', ['role' => 'admin']))->assertForbidden();
    }
}
