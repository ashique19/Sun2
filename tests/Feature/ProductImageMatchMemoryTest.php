<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImageMatchMemory;
use App\Models\User;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\ProductImageMatchMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductImageMatchMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: ChannelConversation, 1: ChannelMessage, 2: string}
     */
    private function inboundImageWithHashes(string $dhash, string $dctHash): array
    {
        $conversation = ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-memory-'.uniqid(),
            'customer_name' => 'Memory Customer',
            'last_inbound_at' => now(),
        ]);

        $relativeDir = 'img/channel-inbound/'.$conversation->id;
        File::ensureDirectoryExists(public_path($relativeDir));
        $relativePath = $relativeDir.'/shot.jpg';
        $absolute = public_path($relativePath);

        $img = imagecreatetruecolor(64, 64);
        $red = imagecolorallocate($img, 200, 40, 40);
        imagefilledrectangle($img, 0, 0, 63, 63, $red);
        imagejpeg($img, $absolute, 90);
        imagedestroy($img);

        $message = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/memory.jpg',
            'media_mime' => 'image/jpeg',
            'media_path' => $relativePath,
            'media_dhash' => $dhash,
            'media_dct_hash' => $dctHash,
            'sent_at' => now(),
        ]);

        return [$conversation, $message, $relativePath];
    }

    private function publishedProduct(string $name = 'Memory Ring'): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => 'memory-'.uniqid(),
            'sku' => 'MEM'.random_int(1000, 9999),
            'price' => 1500,
            'purchase_price' => 700,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
        ]);
    }

    #[Test]
    public function staff_tag_persists_hash_memories_and_auto_match_reuses_them(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $dhash = str_repeat('a', 16);
        $dct = str_repeat('b', 16);
        [$conversation, $message] = $this->inboundImageWithHashes($dhash, $dct);
        $product = $this->publishedProduct();

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->call('tagMatchedProduct', $product->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_image_match_memories', [
            'hash' => $dhash,
            'hash_kind' => 'dhash',
            'product_id' => $product->id,
            'source_channel_message_id' => $message->id,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('product_image_match_memories', [
            'hash' => $dct,
            'hash_kind' => 'dct',
            'product_id' => $product->id,
        ]);

        [, $later] = $this->inboundImageWithHashes($dhash, $dct);
        $later->forceFill(['matched_product_id' => null])->save();

        $match = app(ChannelMessageImageMatchService::class)->bestAutoMatch($later);

        $this->assertNotNull($match);
        $this->assertSame($product->id, $match['product_id']);
        $this->assertSame('staff_memory', $match['strategy']);
        $this->assertSame(100.0, $match['match_percent']);
        $this->assertGreaterThan(0, (int) ProductImageMatchMemory::query()->where('hash', $dhash)->value('hit_count'));
    }

    #[Test]
    public function clearing_tag_forgets_memories_sourced_from_that_message(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $dhash = str_repeat('c', 16);
        $dct = str_repeat('d', 16);
        [$conversation, $message] = $this->inboundImageWithHashes($dhash, $dct);
        $product = $this->publishedProduct('Clearable Ring');

        app(ProductImageMatchMemoryService::class)->rememberFromStaffTag($message, $product, $admin);
        $this->assertSame(2, ProductImageMatchMemory::query()->count());

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('clearMatchedProduct', $message->id)
            ->assertHasNoErrors();

        $this->assertSame(0, ProductImageMatchMemory::query()->count());
        $this->assertNull($message->fresh()->matched_product_id);
    }
}
