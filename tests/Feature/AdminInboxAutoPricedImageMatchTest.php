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
            ->assertDontSee('Match product')
            ->assertDontSee('Open full size')
            ->assertSee('Send priced')
            ->assertSee('Add to order')
            ->assertSee($product->name)
            ->assertSeeHtml('wire:click="sendPricedImageFromMatch('.$inbound->id.')"')
            ->assertSeeHtml('wire:click="addMatchedProductToOrder('.$inbound->id.')"')
            ->assertSeeHtml('wire:click.stop="openTagProductOnImage('.$inbound->id.')"');

        $state = $component->get('inboundImageMatchState');
        $this->assertSame('done', $state[(string) $inbound->id]['status'] ?? null);
        $this->assertSame($product->id, $state[(string) $inbound->id]['product_id'] ?? null);
        $this->assertGreaterThanOrEqual(90.0, (float) ($state[(string) $inbound->id]['match_percent'] ?? 0));
        $this->assertSame($product->id, $inbound->fresh()->matched_product_id);

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
            ->assertSeeHtml('wire:click.stop="openTagProductOnImage('.$inbound->id.')"')
            ->assertDontSeeHtml('wire:click="sendPricedImageFromMatch('.$inbound->id.')"')
            ->assertDontSeeHtml('wire:click="addMatchedProductToOrder('.$inbound->id.')"');
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
            ->assertSee('Send priced')
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
    public function screenshot_with_chrome_matches_via_center_crop_fallback(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        $hasher = app(ProductImageHashService::class);

        $catalog = imagecreatetruecolor(200, 200);
        $a = imagecolorallocate($catalog, 200, 40, 60);
        $b = imagecolorallocate($catalog, 40, 90, 200);
        for ($y = 0; $y < 200; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($catalog, $x, $y, (((int) ($x / 10)) % 2) === 0 ? $a : $b);
            }
        }

        $screenshot = imagecreatetruecolor(400, 400);
        $chrome = imagecolorallocate($screenshot, 18, 18, 22);
        imagefill($screenshot, 0, 0, $chrome);
        imagecopy($screenshot, $catalog, 100, 100, 0, 0, 200, 200);

        ob_start();
        imagepng($catalog);
        $catalogBytes = (string) ob_get_clean();
        imagedestroy($catalog);

        ob_start();
        imagepng($screenshot);
        $screenshotBytes = (string) ob_get_clean();
        imagedestroy($screenshot);

        $product = Product::query()->create([
            'name' => 'Screenshot Match Ring',
            'slug' => 'screenshot-match-ring-'.uniqid(),
            'price' => 2100,
            'purchase_price' => 900,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        file_put_contents($absoluteDir.'/catalog.png', $catalogBytes);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog.png',
            'alt' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'perceptual_hash' => $hasher->hashBinary($catalogBytes),
        ]);

        $customerUrl = 'https://cdn.example.test/screenshot-chrome.png';
        Http::fake([
            $customerUrl => Http::response($screenshotBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $fullMatches = $hasher->findTopMatches(
            $hasher->hashBinary($screenshotBytes),
            1,
            ProductImageHashService::AUTO_MATCH_PERCENT,
        );
        $this->assertSame([], $fullMatches);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Send priced')
            ->assertSee('Add to order');

        $state = $component->get('inboundImageMatchState')[(string) $inbound->id] ?? [];
        $this->assertSame($product->id, $state['product_id'] ?? null);
        $this->assertStringContainsString('center', (string) ($state['strategy'] ?? ''));
        $this->assertSame($product->id, $inbound->fresh()->matched_product_id);
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
