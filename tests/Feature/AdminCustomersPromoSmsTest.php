<?php

namespace Tests\Feature;

use App\Contracts\Sms\SmsSender;
use App\Livewire\Admin\AdminUsers;
use App\Models\Address;
use App\Models\City;
use App\Models\Order;
use App\Models\User;
use App\Services\Sms\LoggingSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCustomersPromoSmsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function customer(string $name, string $phone): User
    {
        Role::findOrCreate('customers');

        $user = User::factory()->create([
            'name' => $name,
            'phone' => $phone,
        ]);
        $user->assignRole('customers');

        return $user;
    }

    private function orderFor(User $customer, string $city): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'address' => 'House 1',
            'city' => $city,
            'subtotal' => 500,
            'delivery_charge' => 80,
            'discount' => 0,
            'total' => 580,
            'cod_amount' => 580,
            'due_amount' => 580,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'new',
            'placed_at' => now(),
        ]);
    }

    public function test_city_filter_matches_order_and_address_city(): void
    {
        $admin = $this->adminUser();
        $dhaka = $this->customer('Dhaka Buyer', '01710000001');
        $chittagong = $this->customer('CTG Buyer', '01710000002');
        $sylhet = $this->customer('Sylhet Buyer', '01710000003');

        $this->orderFor($dhaka, 'Dhaka');
        $this->orderFor($chittagong, 'Chittagong');

        $city = City::query()->create(['name' => 'Sylhet', 'is_active' => true]);
        Address::query()->create([
            'user_id' => $sylhet->id,
            'name' => $sylhet->name,
            'phone' => $sylhet->phone,
            'address' => 'Road 1',
            'city' => 'Sylhet town',
            'city_id' => $city->id,
            'is_default' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->set('cityFilter', 'Dhaka')
            ->assertSee('Dhaka Buyer')
            ->assertDontSee('CTG Buyer')
            ->assertDontSee('Sylhet Buyer');

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->set('cityFilter', 'Sylhet')
            ->assertSee('Sylhet Buyer')
            ->assertDontSee('Dhaka Buyer');

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->set('search', 'Chittagong')
            ->assertSee('CTG Buyer')
            ->assertDontSee('Dhaka Buyer');
    }

    public function test_select_all_none_and_send_promotional_sms(): void
    {
        Config::set('app.debug', false);
        Config::set('sms.driver', 'log');
        Log::spy();

        $admin = $this->adminUser();
        $a = $this->customer('Alpha', '01720000001');
        $b = $this->customer('Beta', '01720000002');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('toggleSelectAllOnPage', [$a->id, $b->id])
            ->assertSet('selectedCustomerIds', [$a->id, $b->id])
            ->call('selectNone')
            ->assertSet('selectedCustomerIds', [])
            ->call('toggleCustomerSelection', $a->id)
            ->call('toggleCustomerSelection', $b->id)
            ->call('openPromoSmsModal')
            ->assertSet('promoSmsModalOpen', true)
            ->set('promoSmsMessage', 'সুন্দরিতমায় ছাড় চলছে!')
            ->set('promoSmsCampaignId', 'promo-1')
            ->call('sendPromoSms')
            ->assertSet('promoSmsModalOpen', false)
            ->assertSet('selectedCustomerIds', [])
            ->assertSet('message', 'Sent 2 promotional SMS.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $levelOrMsg, $context = null): bool => $levelOrMsg === 'Promotional SMS sent'
                || (is_array($context) && ($context['campaign_id'] ?? null) === 'promo-1'))
            ->atLeast()
            ->twice();
    }

    public function test_promotional_sms_uses_mimsms_gateway_type_p(): void
    {
        Config::set('app.debug', false);
        Config::set('sms.driver', 'mimsms');
        Config::set('sms.from', 'Sundoritoma');
        Config::set('sms.mimsms', [
            'api_url' => 'https://api.mimsms.com/api/V2/SMS',
            'username' => 'shop@example.com',
            'api_key' => 'secret-api-key',
            'sender_name' => 'Sundoritoma',
            'promotional_transaction_type' => 'P',
            'promotional_campaign_id' => null,
        ]);

        Http::fake([
            'api.mimsms.com/*' => Http::response([
                'status' => 'Success',
                'responseResult' => 'SMS sent successfully',
            ]),
        ]);

        $admin = $this->adminUser();
        $customer = $this->customer('Promo Target', '01730000001');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('toggleCustomerSelection', $customer->id)
            ->call('openPromoSmsModal')
            ->set('promoSmsMessage', 'নতুন কালেকশন এসেছে!')
            ->call('sendPromoSms')
            ->assertSet('message', 'Sent 1 promotional SMS.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['TransactionType'] === 'P'
            && $request['MobileNumber'] === '8801730000001');
    }

    public function test_cannot_open_promo_modal_without_selection(): void
    {
        $admin = $this->adminUser();
        $this->customer('Lonely', '01740000001');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class, ['segment' => 'customers'])
            ->call('openPromoSmsModal')
            ->assertSet('promoSmsModalOpen', false)
            ->assertSet('error', 'Select at least one customer to send promotional SMS.');
    }

    public function test_log_driver_still_resolves_after_promotional_interface_change(): void
    {
        Config::set('app.debug', false);
        Config::set('sms.driver', 'log');

        $this->assertInstanceOf(LoggingSmsSender::class, app(SmsSender::class));
        app(SmsSender::class)->sendPromotional('01750000001', 'test promo');
    }
}
