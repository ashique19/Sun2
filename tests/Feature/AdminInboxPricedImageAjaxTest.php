<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductPricedImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxPricedImageAjaxTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(array $overrides = []): ChannelConversation
    {
        return ChannelConversation::query()->create(array_merge([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-priced-ajax',
            'customer_name' => 'Priced Ajax Customer',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    private function inboundImage(ChannelConversation $conversation): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_customer_priced_ajax',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/customer-priced.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => now()->subMinute(),
        ]);
    }

    private function makeCategory(string $name = 'Rings'): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);
    }

    private function productWithPrimaryImage(Category $category, string $name, float $price): Product
    {
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'price' => $price,
            'purchase_price' => $price / 2,
            'stock_quantity' => 5,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'primary.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(240, 240);
        $fill = imagecolorallocate($image, 40, 90, 140);
        imagefill($image, 0, 0, $fill);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => $name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return $product->fresh(['images']);
    }

    private function fakeMessenger(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        $messageIdIndex = 0;
        Http::fake(function () use (&$messageIdIndex) {
            $messageIdIndex++;

            return Http::response([
                'message_id' => 'm_priced_ajax_'.$messageIdIndex,
                'recipient_id' => 'psid-priced-ajax',
            ], 200);
        });
    }

    #[Test]
    public function guest_is_redirected_from_priced_image_endpoint(): void
    {
        $this->postJson(route('admin.inbox.send-priced-image'), [
            'conversation_id' => 1,
            'product_id' => 1,
        ])->assertRedirect();
    }

    #[Test]
    public function moderator_cannot_send_priced_image(): void
    {
        Role::findOrCreate('moderator');
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator)
            ->postJson(route('admin.inbox.send-priced-image'), [
                'conversation_id' => 1,
                'product_id' => 1,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function staff_endpoint_sends_priced_image_without_livewire(): void
    {
        $this->fakeMessenger();
        $this->actingAs($this->adminUser());

        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);
        $product = $this->productWithPrimaryImage($this->makeCategory(), 'Gold Ring', 4500);
        app(ProductPricedImageService::class)->generate($product);
        $product->refresh();

        $this->postJson(route('admin.inbox.send-priced-image'), [
            'conversation_id' => $conversation->id,
            'product_id' => $product->id,
            'reply_to_message_id' => $inbound->id,
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'product_id' => $product->id,
                'conversation_id' => $conversation->id,
            ]);

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertNotNull($outbound->media_url);
        $this->assertTrue(is_file(public_path($outbound->media_url)));
    }

    #[Test]
    public function staff_can_send_two_priced_images_back_to_back(): void
    {
        $this->fakeMessenger();
        $this->actingAs($this->adminUser());

        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);
        $category = $this->makeCategory();
        $first = $this->productWithPrimaryImage($category, 'First Ring', 1200);
        $second = $this->productWithPrimaryImage($category, 'Second Ring', 1800);
        app(ProductPricedImageService::class)->generate($first);
        app(ProductPricedImageService::class)->generate($second);

        $this->postJson(route('admin.inbox.send-priced-image'), [
            'conversation_id' => $conversation->id,
            'product_id' => $first->id,
            'reply_to_message_id' => $inbound->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson(route('admin.inbox.send-priced-image'), [
            'conversation_id' => $conversation->id,
            'product_id' => $second->id,
            'reply_to_message_id' => $inbound->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('media_url')
            ->count());
    }

    #[Test]
    public function picker_stays_open_after_priced_image_send(): void
    {
        $this->fakeMessenger();
        $this->actingAs($this->adminUser());

        $conversation = $this->conversation();
        $this->inboundImage($conversation);
        $product = $this->productWithPrimaryImage($this->makeCategory(), 'Keep Open Ring', 2200);
        app(ProductPricedImageService::class)->generate($product);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openComposerProductPicker')
            ->assertSeeHtml('data-inbox-priced-image-send')
            ->assertSeeHtml('data-product-id="'.$product->id.'"')
            ->assertDontSeeHtml('wire:click="sendPricedProductImage('.$product->id.')"')
            ->call('sendPricedProductImage', $product->id)
            ->assertHasNoErrors()
            ->assertSet('composerProductPickerOpen', true)
            ->assertSet('statusMessage', 'Priced image sent.');
    }

    #[Test]
    public function missing_product_returns_not_found(): void
    {
        $this->fakeMessenger();
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();

        $this->postJson(route('admin.inbox.send-priced-image'), [
            'conversation_id' => $conversation->id,
            'product_id' => 999999,
        ])
            ->assertNotFound()
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function inbox_priced_image_js_queues_clicks_outside_livewire(): void
    {
        $source = file_get_contents(resource_path('js/inbox-priced-image-send.js'));
        $boot = file_get_contents(resource_path('js/inbox-image-match.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('data-inbox-priced-image-send', $source);
        $this->assertStringContainsString('inflightKeys', $source);
        $this->assertStringContainsString('stopImmediatePropagation', $source);
        $this->assertStringContainsString("addEventListener('click', onClick, true)", $source);
        $this->assertStringContainsString('refreshFromRealtime', $source);
        $this->assertStringContainsString("import './inbox-priced-image-send.js'", $boot);
    }
}
