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

class AdminInboxComposerProductPickerTest extends TestCase
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
            'external_user_id' => 'psid-picker',
            'customer_name' => 'Picker Customer',
            'last_inbound_at' => now(),
        ], $overrides));
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

    private function productWithPrimaryImage(Category $category, string $name, float $price, int $imageCount = 1): Product
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

        for ($i = 0; $i < $imageCount; $i++) {
            $filename = 'img'.$i.'.jpg';
            $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
            $image = imagecreatetruecolor(240, 240);
            $fill = imagecolorallocate($image, 40 + ($i * 40), 90, 140);
            imagefill($image, 0, 0, $fill);
            imagejpeg($image, $absolute, 90);
            imagedestroy($image);

            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => '/'.$relativeDir.'/'.$filename,
                'alt' => $name.' '.$i,
                'is_primary' => $i === 0,
                'sort_order' => $i,
            ]);
        }

        return $product->fresh(['images']);
    }

    #[Test]
    public function composer_plus_p_opens_product_picker_modal(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $category = $this->makeCategory();
        $this->productWithPrimaryImage($category, 'Gold Ring', 2500);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_picker',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Need options',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSeeHtml('wire:click="openComposerProductPicker"')
            ->assertSee('+P')
            ->call('openComposerProductPicker')
            ->assertSet('composerProductPickerOpen', true)
            ->assertSee('Send product')
            ->assertSee('Gold Ring')
            ->assertSee('A.Img')
            ->assertSee('Link')
            ->assertSee('Price')
            ->assertSee('P.img');
    }

    #[Test]
    public function composer_picker_sends_product_link(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_link_1',
                'recipient_id' => 'psid-picker',
            ], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = $this->productWithPrimaryImage($this->makeCategory(), 'Pearl Drop', 1800);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_link',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Link please',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openComposerProductPicker')
            ->call('sendProductPickerLink', $product->id)
            ->assertHasNoErrors()
            ->assertSet('composerProductPickerOpen', false)
            ->assertSet('statusMessage', 'Product link sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame(route('product.show', $product), $outbound->body);
    }

    #[Test]
    public function composer_picker_sends_price_text(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'page-token',
            'facebook.graph_version' => 'v25.0',
            'app.url' => 'https://example.test',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'message_id' => 'm_price_1',
                'recipient_id' => 'psid-picker',
            ], 200),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = $this->productWithPrimaryImage($this->makeCategory(), 'Silver Band', 1200);

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_price',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Price?',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openComposerProductPicker')
            ->call('sendProductPickerPrice', $product->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Price reply sent.');

        $outbound = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame($product->priceWithUnitLabel(), $outbound->body);
    }

    #[Test]
    public function composer_picker_sends_album_images_and_priced_image(): void
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
                'message_id' => 'm_img_'.$messageIdIndex,
                'recipient_id' => 'psid-picker',
            ], 200);
        });

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $product = $this->productWithPrimaryImage($this->makeCategory(), 'Multi Shot', 3200, 2);
        app(ProductPricedImageService::class)->generate($product);
        $product->refresh();

        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'external_message_id' => 'm_in_album',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'Photos',
            'sent_at' => now()->subMinute(),
        ]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openComposerProductPicker')
            ->call('sendProductPickerAlbumImages', $product->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Sent 2 product images.');

        $this->assertSame(2, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('media_url')
            ->count());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openComposerProductPicker')
            ->call('sendPricedProductImage', $product->id)
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Priced image sent.')
            ->assertSet('composerProductPickerOpen', false);

        $this->assertSame(3, ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('direction', ChannelMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('media_url')
            ->count());
    }
}
