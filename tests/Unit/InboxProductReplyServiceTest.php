<?php

namespace Tests\Unit;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\InboxProductReplyService;
use App\Services\Channels\ChannelReplyService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxProductReplyServiceTest extends TestCase
{
    #[Test]
    public function send_price_uses_the_catalog_price_label(): void
    {
        $conversation = new ChannelConversation(['id' => 11]);
        $product = new Product(['price' => 500, 'price_unit' => 'জোড়া']);
        $replyTo = new ChannelMessage(['id' => 22]);

        $replies = Mockery::mock(ChannelReplyService::class);
        $replies->shouldReceive('sendText')
            ->once()
            ->with($conversation, $product->priceWithUnitLabel(), false, $replyTo)
            ->andReturn(['ok' => true]);

        $result = (new InboxProductReplyService($replies))->sendPrice($conversation, $product, $replyTo);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function send_link_posts_the_storefront_url(): void
    {
        $conversation = new ChannelConversation(['id' => 11]);
        $product = new Product(['id' => 9, 'slug' => 'gold-ring', 'price' => 500]);

        $replies = Mockery::mock(ChannelReplyService::class);
        $replies->shouldReceive('sendText')
            ->once()
            ->withArgs(function ($sentConversation, $body, $force, $replyTo) use ($conversation, $product) {
                return $sentConversation === $conversation
                    && $force === false
                    && $replyTo === null
                    && $body === route('product.show', $product);
            })
            ->andReturn(['ok' => true]);

        $result = (new InboxProductReplyService($replies))->sendLink($conversation, $product);

        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function send_album_fails_when_the_product_has_no_catalog_images(): void
    {
        $conversation = new ChannelConversation(['id' => 11]);
        $product = new Product(['priced_image_path' => null]);
        $product->setRelation('images', collect());

        $replies = Mockery::mock(ChannelReplyService::class);
        $replies->shouldReceive('sendImage')->never();

        $result = (new InboxProductReplyService($replies))->sendAlbum($conversation, $product);

        $this->assertFalse($result['ok']);
        $this->assertSame('This product has no catalog images to send.', $result['error']);
        $this->assertSame(0, $result['sent']);
    }
}
