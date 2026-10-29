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
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

class AdminInboxAutoPricedImageMatchTest extends TestCase
{
    use ProductPhotoFixtures;
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
        file_put_contents($absolute, $this->productPhotoBytes(21, 240, 240));

        $hash = app(ProductImageHashService::class)->hashFile($absolute);

        // No match_signature: the matcher signs legacy catalog rows on first use.
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
            ->assertSee('Price')
            ->assertSee('P.img')
            ->assertSee('A.Img')
            ->assertSee('Link')
            ->assertSee('+Order')
            ->assertSee($product->name)
            ->assertSeeHtml('href="'.route('admin.products.show', $product).'"')
            ->assertSeeHtml('aria-label="Open product details for '.$product->name.'"')
            ->assertSeeHtml('wire:click="sendMatchedProductPriceReply('.$inbound->id.')"')
            ->assertSeeHtml('data-inbox-priced-image-send')
            ->assertSeeHtml('data-reply-to-message-id="'.$inbound->id.'"')
            ->assertSeeHtml('wire:click="sendMatchedProductAlbumImages('.$inbound->id.')"')
            ->assertSeeHtml('wire:click="sendMatchedProductLink('.$inbound->id.')"')
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
        config(['channels.ai_draft.image_min_bytes' => 100]);

        $this->catalogProduct('Unrelated Catalog Ring', $this->productPhotoBytes(31));
        Http::fake([
            'https://cdn.example.test/weak-customer.jpg' => Http::response($this->productPhotoBytes(32), 200, ['Content-Type' => 'image/jpeg']),
        ]);

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
            ->assertDontSeeHtml('wire:click="sendMatchedProductPriceReply('.$inbound->id.')"')
            ->assertDontSeeHtml('data-inbox-priced-image-send')
            ->assertDontSeeHtml('wire:click="addMatchedProductToOrder('.$inbound->id.')"')
            ->assertSee('No match — search product')
            ->assertSeeHtml('wire:click.stop="openTagProductOnImage('.$inbound->id.')"');

        $this->assertNull($inbound->fresh()->matched_product_id);
        $this->assertSame(ChannelMessageImageMatchService::STATUS_NONE, $inbound->fresh()->image_match_meta['status'] ?? null);
    }

    #[Test]
    public function send_matched_product_price_reply_sends_text_price_as_reply(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        [$product, $customerAbsolute, $customerUrl] = $this->productAndMatchingCustomerJpeg();

        Http::fake([
            $customerUrl => Http::response(file_get_contents($customerAbsolute), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://graph.facebook.com/*' => Http::response(['message_id' => 'm_price_text'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_price_reply',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Price')
            ->call('sendMatchedProductPriceReply', $inbound->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Price reply sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertSame($product->priceWithUnitLabel(), $outbound->body);
        $this->assertNull($outbound->media_url);

        @unlink($customerAbsolute);
    }

    #[Test]
    public function send_matched_product_price_reply_retries_without_reply_to_when_messenger_rejects_it(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        [$product, $customerAbsolute, $customerUrl] = $this->productAndMatchingCustomerJpeg();
        $priceAttempts = [];

        Http::fake(function ($request) use ($customerAbsolute, $customerUrl, &$priceAttempts) {
            if ($request->url() === $customerUrl) {
                return Http::response(file_get_contents($customerAbsolute), 200, [
                    'Content-Type' => 'image/jpeg',
                ]);
            }

            $data = $request->data();
            $text = is_array($data['message'] ?? null) ? ($data['message']['text'] ?? null) : null;

            if (is_string($text) && str_starts_with($text, '৳ ')) {
                $priceAttempts[] = $data;

                if (isset($data['reply_to'])) {
                    return Http::response([
                        'error' => [
                            'message' => '(#-1) Unexpected internal error',
                            'type' => 'OAuthException',
                            'code' => -1,
                            'error_subcode' => 2018012,
                            'fbtrace_id' => 'AkXuqmQxOvTy9uTW6y-gE_Z',
                        ],
                    ], 400);
                }

                return Http::response(['message_id' => 'm_price_fallback'], 200);
            }

            return Http::response(['recipient_id' => 'psid-auto-priced'], 200);
        });

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_album_price#1',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'raw_payload' => [
                'message' => [
                    'mid' => 'm_album_price',
                ],
            ],
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('sendMatchedProductPriceReply', $inbound->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Price reply sent.')
            ->assertSet('error', null);

        $this->assertCount(2, $priceAttempts);
        $this->assertSame('m_album_price', $priceAttempts[0]['reply_to']['mid'] ?? null);
        $this->assertArrayNotHasKey('reply_to', $priceAttempts[1]);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertSame($product->priceWithUnitLabel(), $outbound->body);
        $this->assertSame('m_price_fallback', $outbound->external_message_id);

        @unlink($customerAbsolute);
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
            ->assertSee('P.img')
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
    public function send_matched_product_album_images_sends_catalog_images_and_skips_priced(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.messenger.page_id' => 'PAGE42',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        [$product, $customerAbsolute, $customerUrl] = $this->productAndMatchingCustomerJpeg();

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        $secondAbsolute = $absoluteDir.DIRECTORY_SEPARATOR.'catalog-2.jpg';
        $image = imagecreatetruecolor(180, 180);
        $fill = imagecolorallocate($image, 40, 160, 90);
        imagefill($image, 0, 0, $fill);
        imagejpeg($image, $secondAbsolute, 92);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/catalog-2.jpg',
            'alt' => $product->name.' side',
            'is_primary' => false,
            'sort_order' => 1,
            'perceptual_hash' => app(ProductImageHashService::class)->hashFile($secondAbsolute),
        ]);

        app(ProductPricedImageService::class)->generate($product->fresh());
        $product->refresh();
        $this->assertNotEmpty($product->priced_image_path);

        $albumMessageIds = 0;
        Http::fake(function ($request) use ($customerAbsolute, $customerUrl, &$albumMessageIds) {
            if ($request->url() === $customerUrl) {
                return Http::response(file_get_contents($customerAbsolute), 200, [
                    'Content-Type' => 'image/jpeg',
                ]);
            }

            $albumMessageIds++;

            return Http::response(['message_id' => 'm_album_'.$albumMessageIds], 200);
        });

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_album_send',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('A.Img')
            ->call('sendMatchedProductAlbumImages', $inbound->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Sent 2 product images.');

        $outbounds = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $outbounds);
        $this->assertSame($inbound->id, $outbounds[0]->reply_to_message_id);
        $this->assertNull($outbounds[1]->reply_to_message_id);
        $this->assertNotNull($outbounds[0]->media_url);
        $this->assertNotNull($outbounds[1]->media_url);

        @unlink($customerAbsolute);
    }

    #[Test]
    public function send_matched_product_link_sends_storefront_url(): void
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
            'https://graph.facebook.com/*' => Http::response(['message_id' => 'm_link'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $inbound = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_link_send',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $customerUrl,
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);

        $expectedLink = route('product.show', $product);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Link')
            ->call('sendMatchedProductLink', $inbound->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Product link sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertSame($expectedLink, $outbound->body);
        $this->assertNull($outbound->media_url);

        @unlink($customerAbsolute);
    }

    #[Test]
    public function phone_screenshot_with_ui_chrome_auto_matches_the_photo_region(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'channels.ai_draft.image_min_bytes' => 100,
        ]);

        $hasher = app(ProductImageHashService::class);
        $catalogBytes = $this->productPhotoBytes(41);
        $screenshotBytes = $this->phoneScreenshotBytes($catalogBytes);

        $product = $this->catalogProduct('Screenshot Match Ring', $catalogBytes);
        $this->catalogProduct('Other Necklace', $this->productPhotoBytes(42));

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

        $this->assertSame([], $hasher->findTopMatches(
            $hasher->hashBinary($screenshotBytes),
            1,
            ProductImageHashService::AUTO_MATCH_PERCENT,
        ), 'A whole-frame perceptual hash cannot see past the screenshot chrome.');

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('P.img')
            ->assertSee('+Order')
            ->assertSeeHtml('wire:click="rejectMatchedProduct('.$inbound->id.')"');

        $state = $component->get('inboundImageMatchState')[(string) $inbound->id] ?? [];
        $this->assertSame($product->id, $state['product_id'] ?? null);
        $this->assertSame('visual', $state['strategy'] ?? null);
        $this->assertSame($product->id, $inbound->fresh()->matched_product_id);
        $this->assertLessThan(0.7, $inbound->fresh()->image_match_meta['candidates'][0]['region']['height'] ?? 1.0);
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
