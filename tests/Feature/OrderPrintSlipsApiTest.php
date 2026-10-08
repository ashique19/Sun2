<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderPrintSlipsApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'PS-'.uniqid(),
            'name' => 'Print Customer',
            'phone' => '01880001255',
            'address' => 'House 1',
            'city' => 'Dhaka',
            'area' => 'Gulshan',
            'status' => 'dispatched',
            'subtotal' => 1000,
            'delivery_charge' => 80,
            'total' => 1080,
            'cod_amount' => 1080,
            'due_amount' => 1080,
            'courier_consignment_id' => '270697676',
            'placed_at' => now(),
        ], $overrides));
    }

    public function test_signed_print_slips_json_returns_payload_without_auth(): void
    {
        $first = $this->order(['name' => 'Alyssa', 'courier_consignment_id' => '111']);
        $second = $this->order([
            'name' => 'Karim',
            'courier_consignment_id' => '222',
            'order_number' => 'PS-2',
            'phone' => '01710000001',
        ]);

        $url = URL::temporarySignedRoute('print-slips', now()->addMinutes(10), [
            'ids' => $second->id.','.$first->id,
        ]);

        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonPath('cut_after_each', true)
            ->assertJsonPath('slips.0.name', 'Karim')
            ->assertJsonPath('slips.0.parcel_id', '222')
            ->assertJsonPath('slips.0.brand', 'Sundoritoma.com')
            ->assertJsonPath('slips.1.name', 'Alyssa')
            ->assertJsonPath('slips.1.parcel_id', '111')
            ->assertJsonMissingPath('slips.0.due_tk')
            ->assertJsonMissingPath('slips.0.phone')
            ->assertJsonMissingPath('slips.0.address')
            ->assertJsonMissingPath('slips.0.helpline');
    }

    public function test_unsigned_print_slips_is_forbidden(): void
    {
        $order = $this->order();

        $this->getJson(route('print-slips', ['ids' => $order->id]))
            ->assertForbidden();
    }

    public function test_print_selected_page_includes_otg_deep_link(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->order();

        $this->get(route('admin.orders.print-selected', ['ids' => $order->id]))
            ->assertOk()
            ->assertSee('print-otg-app', false)
            ->assertSee('sundoritoma://print?slips_url=', false)
            ->assertSee('Print via OTG app', false)
            ->assertSee('Chrome’s printer list will stay empty', false)
            ->assertDontSee('window.setTimeout(window.printCutEach', false);
    }

    public function test_single_order_print_label_includes_otg_deep_link_without_auto_browser_print(): void
    {
        $this->actingAs($this->adminUser());
        $order = $this->order();

        $this->get(route('admin.orders.print', $order))
            ->assertOk()
            ->assertSee('print-otg-app', false)
            ->assertSee('sundoritoma://print?slips_url=', false)
            ->assertSee('Print via OTG app', false)
            ->assertSee('Browser print (not USB OTG)', false)
            ->assertSee('Sundoritoma.com', false)
            ->assertDontSee('TOTAL DUE', false)
            ->assertDontSee('WhatsApp:', false)
            ->assertDontSee('setTimeout(function () { window.print();', false);
    }
}
