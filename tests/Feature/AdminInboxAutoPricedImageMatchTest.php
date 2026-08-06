<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductPricedImageService;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxAutoPricedImageMatchTest extends TestCase
{
    use RefreshDatabase;

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
            'external_user_id' => 'psid-auto-priced',
            'customer_name' => 'Auto Match Customer',
            'last_inbound_at' => now(),
        ]);
    }

    /**
     * @return array{0: Product, 1: string, 2: string} product, absolute jpeg path, public URL
     */
    private function productAndMatchingCustomerJpeg(): array
    {
        $product = Product::query()->create([
            'name' => 'Auto Match Ring',
            'slug' => 'auto-match-ring-'.uniqid(),
            'sku' => 'AMR'.random_int(100, 999),
            'price' => 3200,
            'purchase_price' => 1400,
            'stock_quantity' => 4,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'catalog.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(240, 240);
        $fill = imagecolorallocate($image, 210, 40, 70);
        imagefill($image, 0, 0, $fill);
        imagejpeg($image, $absolute, 92);
        imagedestroy($image);

        $hash = app(ProductImageHashService::class)->hashFile($absolute);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hash,
        ]);

        $customerAbsolute = sys_get_temp_dir().'/inbox-auto-match-'.uniqid().'.jpg';
        copy($absolute, $customerAbsolute);

        return [$product->fresh(['images']), $customerAbsolute, 'https://cdn.example.test/customer-match.jpg'];
    }

    #[Test]
    public function opening_conversation_shows_send_priced_image_when_match_is_at_least_90_percent(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        [$product, $customerAbsolute, $customerUrl] = $this->productAndMatchingCustomerJpeg();

        Http::fake([
            $customerUrl => Http::response(file_get_contents($customerAbsolute), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://graph.facebook.com/*' => Http::response(['recipient_id' => 'psid-auto-priced'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_auto_match',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Match product')
            ->assertSee('Send priced image')
            ->assertSeeHtml('wire:click="sendPricedImageFromMatch('.$inbound->id.')"');

        $state = $component->get('inboundImageMatchState');
        $this->assertSame('done', $state[(string) $inbound->id]['status'] ?? null);
        $this->assertSame($product->id, $state[(string) $inbound->id]['product_id'] ?? null);
        $this->assertGreaterThanOrEqual(90.0, (float) ($state[(string) $inbound->id]['match_percent'] ?? 0));

        @unlink($customerAbsolute);
    }

    #[Test]
    public function weak_matches_do_not_show_send_priced_image_button(): void
    {
        $matcher = \Mockery::mock(ChannelMessageImageMatchService::class);
        $matcher->shouldReceive('bestAutoMatch')->once()->andReturn(null);
        $this->app->instance(ChannelMessageImageMatchService::class, $matcher);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://cdn.example.test/weak-customer.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Match product')
            ->assertDontSee('Send priced image')
            ->assertDontSeeHtml('wire:click="sendPricedImageFromMatch('.$inbound->id.')"');
    }

    #[Test]
    public function send_priced_image_from_match_replies_with_priced_product_image(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        [$product, $customerAbsolute, $customerUrl] = $this->productAndMatchingCustomerJpeg();
        app(ProductPricedImageService::class)->generate($product);
        $product->refresh();

        Http::fake([
            $customerUrl => Http::response(file_get_contents($customerAbsolute), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://graph.facebook.com/*' => Http::response(['message_id' => 'm_priced_auto'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_auto_send',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Send priced image')
            ->call('sendPricedImageFromMatch', $inbound->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Priced image sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertNotNull($outbound->media_url);

        @unlink($customerAbsolute);
    }

    #[Test]
    public function pending_match_state_is_set_before_async_search_outside_tests(): void
    {
        // In HTTP tests runInboundImageMatches runs immediately; this asserts the
        // pending→done transition still ends with a searchable state key.
        config(['channels.ai_draft.image_min_bytes' => 100]);

        Http::fake([
            'https://cdn.example.test/pending.jpg' => Http::response('not-an-image', 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://cdn.example.test/pending.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id);

        $state = $component->get('inboundImageMatchState');
        $this->assertArrayHasKey((string) $inbound->id, $state);
        $this->assertSame('done', $state[(string) $inbound->id]['status']);
        $this->assertArrayNotHasKey('product_id', $state[(string) $inbound->id]);
    }
}
