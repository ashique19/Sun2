<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\InboxQuickReplyExample;
use App\Models\Product;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Services\Channels\InboxAdvanceAutomationService;
use App\Services\Channels\InboxColorGuaranteeAutomationService;
use App\Services\Channels\InboxLocationAutomationService;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxQuickReplyMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('priceQueries')]
    public function detects_price_queries_in_bangla_english_and_typos(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_PRICE,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function priceQueries(): array
    {
        return [
            ['dam koto?'],
            ['দাম কত'],
            ['eta koto'],
            ['eita koto'],
            ['prize pls'],
            ['price?'],
            ['এটা কত'],
            ['koto tk'],
        ];
    }

    #[Test]
    #[DataProvider('deliveryQueries')]
    public function detects_delivery_time_queries(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_DELIVERY,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function deliveryQueries(): array
    {
        return [
            ['kobe pabo?'],
            ['কবে পাবো'],
            ['delivary koto din'],
            ['কতদিন লাগবে'],
            ['delivery time'],
        ];
    }

    #[Test]
    #[DataProvider('orderQueries')]
    public function detects_order_process_queries(string $text): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_ORDER,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function orderQueries(): array
    {
        return [
            ['kivabe order'],
            ['কিভাবে অর্ডার করব'],
            ['how to order'],
            ['ki vabe nibo'],
            ['order korte chai'],
            ['অর্ডার করতে চাই'],
            ['place order'],
        ];
    }

    #[Test]
    public function detects_an_order_process_saved_reply(): void
    {
        $this->assertTrue($this->matcher()->isOrderProcessReply([
            'label' => 'অর্ডার প্রসেস',
            'body' => 'নাম, ঠিকানা আর মোবাইল দিন',
        ]));
        $this->assertTrue($this->matcher()->isOrderProcessReply([
            'label' => 'Order',
            'body' => 'নাম, ঠিকানা আর মোবাইল দিন',
        ]));
        $this->assertTrue($this->matcher()->looksLikeOrderProcess('kivabe order'));
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_ORDER,
            $this->matcher()->intentForReply([
                'label' => 'অর্ডার প্রসেস',
                'body' => 'কিভাবে অর্ডার করবেন',
            ]),
        );
        $this->assertFalse($this->matcher()->isOrderProcessReply([
            'label' => 'Thanks',
            'body' => 'ধন্যবাদ, অর্ডার কনফার্ম করা হয়েছে।',
        ]));
        $this->assertFalse($this->matcher()->looksLikeOrderProcess('দাম কত'));
    }

    #[Test]
    public function koto_din_prefers_delivery_over_price(): void
    {
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_DELIVERY,
            $this->matcher()->detectIntent('koto din lagbe?'),
        );
    }

    #[Test]
    public function greeting_is_not_an_intent(): void
    {
        $this->assertNull($this->matcher()->detectIntent('hello'));
        $this->assertNull($this->matcher()->detectIntent('আসসালামু আলাইকুম'));
    }

    #[Test]
    public function maps_price_intent_to_a_price_quick_reply(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $message = $this->inbound($conversation, 'dam koto?');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
        $this->assertFalse($matches[$message->id]['tagged']);
    }

    #[Test]
    public function tagged_example_matches_a_typo_variant(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $tagged = $this->inbound($conversation, 'shippy when');
        $this->matcher()->tag($tagged, $replies[1]['id'], null);

        $later = $this->inbound($conversation, 'shippy wen');
        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[1]['id'], $matches[$tagged->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_TAGGED, $matches[$tagged->id]['via']);
        $this->assertTrue($matches[$tagged->id]['tagged']);
        $this->assertSame($replies[1]['id'], $matches[$later->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_EXAMPLE, $matches[$later->id]['via']);
        $this->assertSame(1, InboxQuickReplyExample::query()->count());
    }

    #[Test]
    public function detects_which_product_replies(): void
    {
        $this->assertTrue($this->matcher()->isWhichProductReply([
            'label' => 'কোন প্রডাক্ট?',
            'body' => 'কোন প্রডাক্টটা দেখতে চান?',
        ]));
        $this->assertTrue($this->matcher()->isWhichProductReply([
            'label' => 'Which product?',
            'body' => 'Which product do you mean?',
        ]));
        $this->assertFalse($this->matcher()->isWhichProductReply([
            'label' => 'Price',
            'body' => 'দাম ১২০০ টাকা',
        ]));
    }

    #[Test]
    public function detects_the_hi_saved_reply_and_short_greetings(): void
    {
        $this->assertTrue($this->matcher()->isHiReply([
            'label' => 'Hi',
            'body' => 'Hello there',
        ]));
        $this->assertFalse($this->matcher()->isHiReply([
            'label' => 'Salaam',
            'body' => 'আসসালামু আলাইকুম',
        ]));
        $this->assertTrue($this->matcher()->looksLikeHi('Hi'));
        $this->assertTrue($this->matcher()->looksLikeHi('hello'));
        $this->assertFalse($this->matcher()->looksLikeHi('আসসালামু আলাইকুম'));
        $this->assertFalse($this->matcher()->looksLikeHi('dam koto?'));
    }

    #[Test]
    public function detects_the_delivery_charge_saved_reply_and_wording(): void
    {
        $this->assertTrue($this->matcher()->isDeliveryChargeReply([
            'label' => 'ডেলিভারি চার্জ ও সময়',
            'body' => 'ডেলিভারি চার্জ ১২০ টাকা, ৩-৫ দিনের মাঝে পাবেন।',
        ]));
        $this->assertTrue($this->matcher()->isDeliveryChargeReply([
            'label' => 'Delivery Time & Charge',
            'body' => 'Delivery charge 120 taka.',
        ]));
        $this->assertFalse($this->matcher()->isDeliveryChargeReply([
            'label' => 'Salaam',
            'body' => 'আসসালামু আলাইকুম',
        ]));
        $this->assertTrue($this->matcher()->looksLikeDeliveryCharge('ডেলিভারি চার্জ ও সময়'));
        $this->assertTrue($this->matcher()->looksLikeDeliveryCharge('Delivery koto'));
        $this->assertTrue($this->matcher()->looksLikeDeliveryCharge('কতদিন লাগবে'));
        $this->assertFalse($this->matcher()->looksLikeDeliveryCharge('দাম কত'));
        $this->assertFalse($this->matcher()->looksLikeDeliveryCharge('কবে পাবো'));
        $this->assertFalse($this->matcher()->looksLikeDeliveryCharge('কবে পাব'));
        $this->assertFalse($this->matcher()->looksLikeDeliveryCharge('আপু আমার পার্সেল টা কবে পাঠাবেন???'));
        $this->assertFalse($this->matcher()->looksLikeDeliveryCharge('কুমিল্লা এডভান্স লাগবে?'));
        $this->assertTrue($this->matcher()->looksLikeKobePabo('কবে পাব'));
        $this->assertTrue($this->matcher()->looksLikeKobePabo('কবে পাবো'));
        $this->assertTrue($this->matcher()->looksLikeKobePabo('kobe pabo?'));
        $this->assertTrue($this->matcher()->looksLikeKobePabo('আমারটা কবে পাব'));
        $this->assertFalse($this->matcher()->looksLikeKobePabo('কবে পাবেন'));
        $this->assertFalse($this->matcher()->looksLikeKobePabo('আপু আমার পার্সেল টা কবে পাঠাবেন???'));
        $this->assertFalse($this->matcher()->looksLikeKobePabo('ডেলিভারি চার্জ কত'));
        $this->assertFalse($this->matcher()->looksLikeKobePabo('কতদিন লাগবে'));
        $this->assertFalse($this->matcher()->looksLikeLocation('কবে পাব'));
    }

    #[Test]
    public function detects_advance_wording_and_cod_push(): void
    {
        $this->assertTrue($this->matcher()->isAdvanceReply([
            'label' => InboxAdvanceAutomationService::REPLY_LABEL,
            'body' => InboxAdvanceAutomationService::BODY_ASK_LOCATION,
        ]));
        $this->assertFalse($this->matcher()->isAdvanceReply([
            'label' => 'Address?',
            'body' => 'আপনার সম্পূর্ণ ঠিকানাটা একটু দিবেন?',
        ]));
        $this->assertTrue($this->matcher()->looksLikeAdvance('এডভান্স লাগবে?'));
        $this->assertTrue($this->matcher()->looksLikeAdvance('teknaf e advance lagbe?'));
        $this->assertTrue($this->matcher()->looksLikeAdvance('উখিয়া তে আগে ডেলিভারি চার্জ দিতে হবে?'));
        $this->assertFalse($this->matcher()->looksLikeAdvance('কুমিল্লা ডেলিভারি কত'));
        $this->assertFalse($this->matcher()->looksLikeAdvance('দাম কত'));
        $this->assertTrue($this->matcher()->looksLikeCodPush('পুরো ক্যাশ অন ডেলিভারি চাই'));
        $this->assertTrue($this->matcher()->looksLikeCodPush('এডভান্স ছাড়া পাঠাও'));
        $this->assertFalse($this->matcher()->looksLikeCodPush('এডভান্স লাগবে?'));
        $this->assertFalse($this->matcher()->looksLikeLocation('এডভান্স লাগবে?'));
    }

    #[Test]
    public function detects_the_location_saved_reply_and_wording(): void
    {
        $this->assertTrue($this->matcher()->isLocationReply([
            'label' => InboxLocationAutomationService::REPLY_LABEL,
            'body' => InboxLocationAutomationService::BODY,
        ]));
        $this->assertTrue($this->matcher()->isLocationReply([
            'label' => 'Showroom',
            'body' => 'ignored',
        ]));
        $this->assertFalse($this->matcher()->isLocationReply([
            'label' => 'Address?',
            'body' => 'আপনার সম্পূর্ণ ঠিকানাটা একটু দিবেন?',
        ]));
        $this->assertTrue($this->matcher()->looksLikeLocation('শোরুম আছে?'));
        $this->assertTrue($this->matcher()->looksLikeLocation('showroom kothay?'));
        $this->assertTrue($this->matcher()->looksLikeLocation('আপনাদের ঠিকানা কি'));
        $this->assertTrue($this->matcher()->looksLikeLocation('location?'));
        $this->assertTrue($this->matcher()->looksLikeLocation('লোকেশন?'));
        $this->assertTrue($this->matcher()->looksLikeLocation('অফিস কোথায়'));
        $this->assertFalse($this->matcher()->looksLikeLocation('মিরপুর ডেলিভারি কত'));
        $this->assertFalse($this->matcher()->looksLikeLocation('আমার ঠিকানা মিরপুর'));
        $this->assertFalse($this->matcher()->looksLikeLocation('দাম কত'));
        $this->assertFalse($this->matcher()->looksLikeLocation('mirpur location'));
    }

    #[Test]
    public function detects_the_color_guarantee_saved_reply_and_wording(): void
    {
        $this->assertTrue($this->matcher()->isColorGuaranteeReply([
            'label' => InboxColorGuaranteeAutomationService::REPLY_LABEL,
            'body' => 'হ্যাঁ, কালার গ্যারান্টি আছে।',
        ]));
        $this->assertFalse($this->matcher()->isColorGuaranteeReply([
            'label' => 'Salaam',
            'body' => 'আসসালামু আলাইকুম',
        ]));
        $this->assertTrue($this->matcher()->looksLikeColorGuarantee('কালার গ্যারান্টি আছে?'));
        $this->assertTrue($this->matcher()->looksLikeColorGuarantee('color guarantee?'));
        $this->assertFalse($this->matcher()->looksLikeColorGuarantee('dam koto?'));
        $this->assertTrue($this->matcher()->isPahariAlchoraCategoryName(InboxColorGuaranteeAutomationService::CATEGORY_NAME));
        $this->assertTrue($this->matcher()->isPahariAlchoraCategoryName('পাহাড়ি আলছড়া'));
        $this->assertFalse($this->matcher()->isPahariAlchoraCategoryName('Rings'));
    }

    #[Test]
    #[DataProvider('priceNegotiateQueries')]
    public function detects_price_negotiate_wording(string $text): void
    {
        $this->assertTrue($this->matcher()->looksLikePriceNegotiate($text));
        $this->assertFalse($this->matcher()->looksLikePriceQuery($text));
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_NEGOTIATE,
            $this->matcher()->detectIntent($text),
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function priceNegotiateQueries(): array
    {
        return [
            ['discount hobe?'],
            ['dam komu'],
            ['কমাবেন আপু'],
            ['last price den'],
            ['ডিস্কাউন্ট দেন'],
            ['একটু কম দিবেন'],
        ];
    }

    #[Test]
    public function price_asks_are_not_treated_as_negotiate(): void
    {
        $this->assertFalse($this->matcher()->looksLikePriceNegotiate('দাম কত'));
        $this->assertFalse($this->matcher()->looksLikePriceNegotiate('dam koto?'));
        $this->assertTrue($this->matcher()->looksLikePriceQuery('দাম কত'));
        $this->assertTrue($this->matcher()->looksLikePriceQuery('dam koto?'));
        $this->assertSame(
            InboxQuickReplyMatcher::INTENT_PRICE,
            $this->matcher()->detectIntent('দাম কত'),
        );
    }

    #[Test]
    public function detects_a_fixed_price_saved_reply(): void
    {
        $this->assertTrue($this->matcher()->isPriceNegotiateReply([
            'label' => 'দাম ফিক্সড',
            'body' => 'দাম ফিক্সড আপু',
        ]));
        $this->assertFalse($this->matcher()->isPriceNegotiateReply([
            'label' => 'কোন প্রডাক্ট?',
            'body' => 'কোন প্রডাক্টের দাম জানতে চান?',
        ]));
    }

    #[Test]
    public function color_guarantee_matches_only_when_the_session_product_is_pahari_alchora(): void
    {
        $replies = app(InboxQuickReplyService::class)->normalize([
            ['label' => InboxColorGuaranteeAutomationService::REPLY_LABEL, 'body' => 'হ্যাঁ, কালার গ্যারান্টি আছে।'],
        ]);

        $withoutProduct = $this->conversation('psid-qr-color-none');
        $plain = $this->inbound($withoutProduct, 'কালার গ্যারান্টি আছে?');
        $plainMatches = $this->matcher()->matchThread($withoutProduct->fresh()->load('messages'), $replies);
        $this->assertArrayNotHasKey($plain->id, $plainMatches);

        $other = $this->conversation('psid-qr-color-other');
        $this->inboundMatchedPhoto($other, $this->productInCategory('Rings'), 0.91, now()->subMinute());
        $otherAsk = $this->inbound($other, 'কালার গ্যারান্টি আছে?');
        $otherMatches = $this->matcher()->matchThread($other->fresh()->load('messages'), $replies);
        $this->assertArrayNotHasKey($otherAsk->id, $otherMatches);

        $pahari = $this->conversation('psid-qr-color-pahari');
        $this->inboundMatchedPhoto(
            $pahari,
            $this->productInCategory(InboxColorGuaranteeAutomationService::CATEGORY_NAME),
            0.91,
            now()->subMinute(),
        );
        $pahariAsk = $this->inbound($pahari, 'কালার গ্যারান্টি আছে?');
        $pahariMatches = $this->matcher()->matchThread($pahari->fresh()->load('messages'), $replies);
        $this->assertSame($replies[0]['id'], $pahariMatches[$pahariAsk->id]['id']);

        $tagged = $this->conversation('psid-qr-color-tagged');
        $taggedAsk = $this->inbound($tagged, 'এটার রং থাকবে?');
        $taggedAsk->forceFill(['quick_reply_id' => $replies[0]['id']])->save();
        $taggedMatches = $this->matcher()->matchThread($tagged->fresh()->load('messages'), $replies);
        $this->assertSame($replies[0]['id'], $taggedMatches[$taggedAsk->id]['id']);
        $this->assertTrue($taggedMatches[$taggedAsk->id]['tagged']);
    }

    #[Test]
    public function price_query_matches_which_product_only_when_no_customer_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-plain');
        $message = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
    }

    #[Test]
    public function price_query_skips_which_product_when_customer_already_sent_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-photo');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'এটা কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertArrayNotHasKey($message->id, $matches);
    }

    #[Test]
    public function price_query_falls_through_to_a_price_quote_when_photos_block_which_product(): void
    {
        $replies = $this->whichProductReplies();
        $replies[] = [
            'id' => 'qr_price_quote',
            'label' => 'Price',
            'body' => 'দাম ১২০০ টাকা',
        ];
        $conversation = $this->conversation('psid-qr-which-fallback');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'dam koto?');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame('qr_price_quote', $matches[$message->id]['id']);
        $this->assertSame(InboxQuickReplyMatcher::VIA_INTENT, $matches[$message->id]['via']);
    }

    #[Test]
    public function tagged_which_product_stays_even_when_photos_exist(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-tagged');
        $this->inboundPhoto($conversation, now()->subMinute());
        $message = $this->inbound($conversation, 'দাম কত');
        $this->matcher()->tag($message, $replies[0]['id'], null);

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
        $this->assertTrue($matches[$message->id]['tagged']);
    }

    #[Test]
    public function example_trained_price_query_skips_which_product_after_photos(): void
    {
        $replies = [
            [
                'id' => 'qr_which_plain',
                'label' => 'কোন প্রডাক্ট?',
                'body' => 'কোন প্রডাক্টটা দেখতে চান?',
            ],
        ];
        $trainer = $this->conversation('psid-qr-which-train');
        $tagged = $this->inbound($trainer, 'দাম কত');
        $this->matcher()->tag($tagged, $replies[0]['id'], null);

        $conversation = $this->conversation('psid-qr-which-later');
        $this->inboundPhoto($conversation, now()->subMinute());
        $later = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertArrayNotHasKey($later->id, $matches);
    }

    #[Test]
    public function stickers_do_not_count_as_product_photos(): void
    {
        $replies = $this->whichProductReplies();
        $conversation = $this->conversation('psid-qr-which-sticker');
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/sticker.png',
            'media_mime' => 'image/png',
            'raw_payload' => ['sticker_id' => 'sticker-1'],
            'sent_at' => now()->subMinute(),
        ]);
        $message = $this->inbound($conversation, 'দাম কত');

        $matches = $this->matcher()->matchThread($conversation->fresh()->load('messages'), $replies);

        $this->assertSame($replies[0]['id'], $matches[$message->id]['id']);
    }

    #[Test]
    public function bulk_priced_targets_keep_only_unreplied_matches_above_84(): void
    {
        $conversation = $this->conversation('psid-qr-bulk');
        $high = Product::query()->create([
            'name' => 'High Match',
            'slug' => 'high-match-'.uniqid(),
            'sku' => 'HM1',
            'price' => 900,
            'purchase_price' => 400,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $low = Product::query()->create([
            'name' => 'Low Match',
            'slug' => 'low-match-'.uniqid(),
            'sku' => 'LM1',
            'price' => 800,
            'purchase_price' => 300,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $edge = Product::query()->create([
            'name' => 'Edge Match',
            'slug' => 'edge-match-'.uniqid(),
            'sku' => 'EM1',
            'price' => 700,
            'purchase_price' => 250,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $replied = Product::query()->create([
            'name' => 'Already Replied',
            'slug' => 'replied-match-'.uniqid(),
            'sku' => 'RM1',
            'price' => 600,
            'purchase_price' => 200,
            'stock_quantity' => 2,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $keep = $this->inboundMatchedPhoto($conversation, $high, 0.91, now()->subMinutes(4));
        $this->inboundMatchedPhoto($conversation, $low, 0.80, now()->subMinutes(3));
        $this->inboundMatchedPhoto($conversation, $edge, 0.84, now()->subMinutes(2));
        $done = $this->inboundMatchedPhoto($conversation, $replied, 0.99, now()->subMinutes(1));
        ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_OUTBOUND,
            'body' => 'priced',
            'reply_to_message_id' => $done->id,
            'sent_at' => now(),
        ]);

        $targets = $this->matcher()->bulkPricedImageTargets(
            $conversation->fresh()->load('messages'),
            app(ChannelMessageImageMatchService::class),
        );

        $this->assertSame([$keep->id], array_column($targets, 'message_id'));
        $this->assertSame([$high->id], array_column($targets, 'product_id'));
        $this->assertSame([91.0], array_column($targets, 'match_percent'));
    }

    #[Test]
    public function highlights_priced_image_when_a_nearby_inbound_asks_price(): void
    {
        $replies = $this->replies();
        $conversation = $this->conversation();
        $product = Product::query()->create([
            'name' => 'Silk Kurti',
            'slug' => 'silk-kurti-'.uniqid(),
            'sku' => 'SK1',
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 3,
            'is_published' => true,
            'display_order' => 0,
        ]);
        $this->inbound($conversation, 'dam koto?', now()->subMinute());
        $photo = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/photo.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'sent_at' => now(),
        ]);

        $thread = $conversation->fresh()->load('messages');
        $matches = $this->matcher()->matchThread($thread, $replies);

        $this->assertTrue($this->matcher()->shouldHighlightPricedImage(
            $photo->fresh(),
            $thread->messages,
            $matches,
        ));
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function replies(): array
    {
        return app(InboxQuickReplyService::class)->normalize([
            ['label' => 'Price', 'body' => 'দাম ১২০০ টাকা'],
            ['label' => 'Delivery', 'body' => '৩-৫ দিনে পৌঁছাবে'],
            ['label' => 'Order', 'body' => 'নাম, ঠিকানা আর মোবাইল দিন'],
        ]);
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    private function whichProductReplies(): array
    {
        return app(InboxQuickReplyService::class)->normalize([
            ['label' => 'কোন প্রডাক্ট?', 'body' => 'কোন প্রডাক্টের দাম জানতে চাইলে বলুন কোনটা'],
        ]);
    }

    private function matcher(): InboxQuickReplyMatcher
    {
        return app(InboxQuickReplyMatcher::class);
    }

    private function conversation(string $psid = 'psid-qr-match'): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Karim',
            'last_inbound_at' => now(),
        ]);
    }

    private function inboundPhoto(ChannelConversation $conversation, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/product-'.uniqid().'.jpg',
            'media_mime' => 'image/jpeg',
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function inboundMatchedPhoto(
        ChannelConversation $conversation,
        Product $product,
        float $score,
        mixed $sentAt = null,
    ): ChannelMessage {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://example.test/matched-'.$product->id.'.jpg',
            'media_mime' => 'image/jpeg',
            'matched_product_id' => $product->id,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'status' => 'matched',
                'candidates' => [
                    ['product_id' => $product->id, 'score' => $score, 'source' => 'catalog'],
                ],
            ],
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    private function productInCategory(string $name): Product
    {
        $category = Category::query()->create([
            'name' => $name,
            'slug' => 'cat-qr-'.uniqid(),
            'display_order' => 0,
            'is_homepage' => true,
            'is_active' => true,
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'QR '.$name,
            'slug' => 'qr-prod-'.uniqid(),
            'price' => 1200,
            'purchase_price' => 400,
            'stock_quantity' => 2,
            'is_published' => true,
        ]);
    }

    private function inbound(ChannelConversation $conversation, string $body, mixed $sentAt = null): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => $body,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}
