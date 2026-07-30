<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminDashboard;
use App\Models\AdminAttentionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function clear_attention_state_renders_a_compact_row(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(AdminDashboard::class)
            ->assertSeeHtml('aria-label="Create order"')
            ->assertSee(route('admin.orders.create'), false)
            ->assertSeeHtml('aria-label="Open inbox"')
            ->assertSee(route('admin.inbox'), false)
            ->assertSee('Admin Attention')
            ->assertSee('All clear')
            ->assertDontSee('No issues need attention at the moment.')
            ->assertDontSee('Needs Attention (')
            ->assertDontSee('Recently Resolved (')
            ->assertSeeHtml('grid-cols-3')
            ->assertSee('New')
            ->assertSee('Draft by AI')
            ->assertSee('Dispatched')
            ->assertDontSee('View orders')
            ->assertSee('OQ')
            ->assertSee('OV')
            ->assertSee('DQ')
            ->assertSee('CV')
            ->assertSeeHtml('aria-label="Order quantity"')
            ->assertSeeHtml('aria-label="Order value"')
            ->assertSeeHtml('aria-label="Delivered quantity"')
            ->assertSeeHtml('aria-label="Collected value"')
            ->assertSeeHtml('role="tooltip"')
            ->assertDontSee('Order Qty')
            ->assertDontSee('Order Value')
            ->assertDontSee('Delivery Qty')
            ->assertDontSee('Collected Value')
            ->assertSee('Orders by date')
            ->assertSee('Current month')
            ->assertSee('Previous month')
            ->assertSee(now()->format('M-d'))
            ->assertSeeHtml('table-fixed')
            ->assertDontSee('Order qty/value by placed date')
            ->assertDontSee('Last 30 Days')
            ->assertDontSee('30-day total')
            ->assertDontSee('Both months total');
    }

    #[Test]
    public function unresolved_attention_expands_into_a_compact_list(): void
    {
        $this->actingAs($this->adminUser());

        AdminAttentionItem::query()->create([
            'issue_type' => AdminAttentionItem::ISSUE_TYPE_SYSTEM_ALERT,
            'title' => 'Token refresh needed',
            'description' => 'Page token looks expired.',
        ]);

        Livewire::test(AdminDashboard::class)
            ->assertSee('Admin Attention')
            ->assertSee('1 issue needs review')
            ->assertSee('Token refresh needed')
            ->assertSee('Resolve')
            ->assertDontSee('All clear');
    }
}
