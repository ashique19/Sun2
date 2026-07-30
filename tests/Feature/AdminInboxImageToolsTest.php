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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxImageToolsTest extends TestCase
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
            'external_user_id' => 'psid-tools',
            'customer_name' => 'Nila',
            'last_inbound_at' => now(),
        ], $overrides));
    }

    private function inboundImage(ChannelConversation $conversation): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_customer_img',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => null,
            'media_url' => 'https://example.test/customer.jpg',
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
        $image = imagecreatetruecolor(320, 320);
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

        return $product->fresh(['images', 'category']);
    }

    #[Test]
    public function inbound_images_show_edit_and_priced_send_icon_buttons(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('wire:click="openImageEdit('.$inbound->id.')"')
            ->assertSeeHtml('wire:click="openPricedImageSend('.$inbound->id.')"')
            ->assertSeeHtml('aria-label="Edit image and send"')
            ->assertSeeHtml('aria-label="Search products and send priced image"');
    }

    #[Test]
    public function edited_image_can_be_sent_as_reply_to_customer_photo(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-tools'], 200)
                ->push(['message_id' => 'm_edited_1'], 200)
                ->push(['recipient_id' => 'psid-tools'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);
        $file = UploadedFile::fake()->image('edited.jpg', 80, 80);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openImageEdit', $inbound->id)
            ->assertSet('imageEditMessageId', $inbound->id)
            ->assertSee('Edit & send image')
            ->assertSee('Text on image')
            ->set('editedReplyImage', $file)
            ->call('sendEditedImageReply')
            ->assertHasNoErrors()
            ->assertSet('imageEditMessageId', null)
            ->assertSet('statusMessage', 'Edited image sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertNotNull($outbound->media_url);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertTrue(is_file(public_path($outbound->media_url)));

        Http::assertSent(function ($request) {
            $message = $request['message'] ?? null;

            return is_array($message)
                && ($message['attachment']['type'] ?? null) === 'image'
                && ($request['reply_to']['mid'] ?? null) === 'm_customer_img';
        });
    }

    #[Test]
    public function priced_product_search_filters_and_sends_priced_image(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-tools'], 200)
                ->push(['message_id' => 'm_priced_1'], 200)
                ->push(['recipient_id' => 'psid-tools'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);

        $rings = $this->makeCategory('Rings');
        $necklaces = $this->makeCategory('Necklaces');
        $cheapRing = $this->productWithPrimaryImage($rings, 'Tiny Ring', 800);
        $priceyRing = $this->productWithPrimaryImage($rings, 'Gold Ring', 4500);
        $this->productWithPrimaryImage($necklaces, 'Pearl Necklace', 2200);

        app(ProductPricedImageService::class)->generate($priceyRing);
        $priceyRing->refresh();
        $this->assertNotEmpty($priceyRing->priced_image_path);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openPricedImageSend', $inbound->id)
            ->assertSet('pricedSendMessageId', $inbound->id)
            ->assertSee('Send priced product image')
            ->assertSee('Tiny Ring')
            ->assertSee('Gold Ring')
            ->assertSee('Pearl Necklace')
            ->set('pricedSendCategory', (string) $rings->id)
            ->assertDontSee('Pearl Necklace')
            ->assertSee('Tiny Ring')
            ->set('pricedSendPriceMin', '3000')
            ->assertDontSee('Tiny Ring')
            ->assertSee('Gold Ring')
            ->assertSeeHtml('wire:click="sendPricedProductImage('.$priceyRing->id.')"')
            ->call('sendPricedProductImage', $priceyRing->id)
            ->assertHasNoErrors()
            ->assertSet('pricedSendMessageId', null)
            ->assertSet('statusMessage', 'Priced image sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertNotNull($outbound->media_url);
        $this->assertSame($inbound->id, $outbound->reply_to_message_id);
        $this->assertTrue(is_file(public_path($outbound->media_url)));

        // Source priced file remains; outbound is a copied channel-replies file.
        $this->assertTrue(is_file(public_path(ltrim((string) $priceyRing->priced_image_path, '/'))));
        unset($cheapRing);
    }

    #[Test]
    public function priced_send_generates_missing_priced_image_before_sending(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me/messages*' => Http::sequence()
                ->push(['recipient_id' => 'psid-tools'], 200)
                ->push(['message_id' => 'm_priced_gen'], 200)
                ->push(['recipient_id' => 'psid-tools'], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $inbound = $this->inboundImage($conversation);
        $category = $this->makeCategory('Bangles');
        $product = $this->productWithPrimaryImage($category, 'Silver Bangle', 1500);

        $this->assertNull($product->priced_image_path);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openPricedImageSend', $inbound->id)
            ->call('sendPricedProductImage', $product->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Priced image sent.');

        $this->assertNotEmpty($product->fresh()->priced_image_path);
        $this->assertSame(1, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('media_url')
            ->count());
    }

    #[Test]
    public function inbox_image_edit_js_registers_alpine_component(): void
    {
        $source = file_get_contents(resource_path('js/inbox-image-edit.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Alpine.data('inboxImageEdit'", $source);
        $this->assertStringContainsString('overlayText', $source);
        $this->assertStringContainsString('sendEdited', $source);
        $this->assertStringContainsString('editedReplyImage', $source);
    }
}
