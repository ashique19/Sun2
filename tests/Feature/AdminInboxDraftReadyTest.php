<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\Area;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use App\Services\Channels\ChannelOrderDraftCatcher;
use App\Services\Channels\ChannelOrderDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxDraftReadyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_order_stub_does_not_show_the_draft_ready_chip(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('toggleOrderPanel')
            ->assertDontSeeHtml('data-inbox-draft-ready');
    }

    #[Test]
    public function caught_draft_shows_chip_and_confirm_moves_it_to_new(): void
    {
        $this->actingAs($this->adminUser());
        config(['gemini.api_key' => null]);

        $product = $this->product();
        $this->seedDhakaMirpur();
        $conversation = $this->conversation();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/selected.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now()->subMinutes(2),
        ]);
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => "Nila\n01627237432\nMirpur, Dhaka",
            'sent_at' => now(),
        ]);

        $draft = app(ChannelOrderDraftCatcher::class)->catch($conversation->fresh());
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isAiDraft());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-draft-ready')
            ->assertSee('Draft ready')
            ->assertSee('Silk Kurti')
            ->assertSee('01627237432')
            ->assertSee('Mirpur')
            ->assertSee('Edit')
            ->assertSee('Confirm')
            ->call('confirmConversationDraft')
            ->assertSee('Draft confirmed and moved to New.')
            ->assertDontSeeHtml('data-inbox-draft-ready');

        $this->assertSame('new', $draft->fresh()->status);
        $this->assertNull($conversation->fresh()->draft_order_id);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    #[Test]
    public function confirm_without_city_and_area_stays_a_draft(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $draft = app(ChannelOrderDraftService::class)->ensureDraftForConversation($conversation);
        $draft->forceFill([
            'phone' => '01627237432',
            'address' => 'Somewhere',
            'city' => null,
            'area' => null,
            'ai_parse_meta' => [
                'source' => 'local+image',
                'caught_via' => 'intent_gate',
                'confidence' => 0.8,
                'missing' => [],
                'weak_points' => [],
            ],
        ])->save();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('data-inbox-draft-ready')
            ->call('confirmConversationDraft')
            ->assertSee('City and area are required before confirming this draft.');

        $this->assertTrue($draft->fresh()->isAiDraft());
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-draft-ready',
            'customer_name' => 'Shayamoli Hossain',
            'last_inbound_at' => now(),
        ]);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-ready-'.uniqid(),
            'sku' => 'RK'.random_int(100, 999),
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 10,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    private function seedDhakaMirpur(): void
    {
        $city = City::query()->firstOrCreate(
            ['name' => 'Dhaka'],
            [
                'division' => null,
                'is_dhaka' => true,
                'is_active' => true,
                'slug' => 'dhaka-'.uniqid(),
            ],
        );

        Area::query()->firstOrCreate(
            ['city_id' => $city->id, 'name' => 'Mirpur'],
            [
                'police_station' => null,
                'is_active' => true,
                'unit_type' => 'thana',
                'slug' => 'mirpur-'.uniqid(),
                'delivery_charge_upto_5' => 80,
                'delivery_charge_over_5' => 150,
            ],
        );
    }
}
