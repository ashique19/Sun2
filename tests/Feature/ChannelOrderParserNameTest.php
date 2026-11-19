<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\ChannelOrderParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelOrderParserNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gemini.api_key' => null]);
    }

    #[Test]
    public function typed_name_several_bubbles_before_phone_beats_facebook_and_chatter(): void
    {
        $conversation = $this->conversation('PSID_HAMIDA', 'Hamida Ahmed');
        $this->inbound($conversation, [
            'parben??',
            'Tanzima khan',
            'Shohag clinic, puran building, rajapur, jhalokathi',
            '01883104511',
            'Order confirm kore rakhen',
            'December er 2 0 tarikh delivery dben',
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertSame('Tanzima khan', $parsed['name']);
        $this->assertSame('01883104511', $parsed['phone']);
    }

    #[Test]
    public function labeled_bangla_name_is_preferred(): void
    {
        $conversation = $this->conversation('PSID_MIKA', 'মিকা চাকমা');
        $this->inbound($conversation, [
            "নাম:মিকা চাকমা।\nউপজেলা:ফটিকছড়ি।\nজেলা: চট্টগ্রাম ।\nঠিকানা:থিরাম বাজার।\nফোন নম্বর:০১৮৯৭৪৬৬৮৮৪",
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertSame('মিকা চাকমা', $parsed['name']);
        $this->assertSame('01897466884', $parsed['phone']);
    }

    #[Test]
    public function leading_name_on_a_comma_address_line_is_captured(): void
    {
        $conversation = $this->conversation('PSID_UJERUNG', 'Ujerung Tripura');
        $this->inbound($conversation, [
            "Ujerung tripura,khagrachari,panchari,kuradiya tarabonchara,\n0 1 8 5 7 1 2 0 7 5 6",
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertSame('Ujerung tripura', $parsed['name']);
        $this->assertSame('01857120756', $parsed['phone']);
    }

    #[Test]
    public function name_before_separate_locality_bubbles_is_kept(): void
    {
        $conversation = $this->conversation('PSID_SATHI', 'Sathi Mondal');
        $this->inbound($conversation, [
            'Ok',
            'Sathi Mondal',
            'Munshirhat',
            'Chauddagram',
            'Cumilla',
            '0 1 7 5 6 8 0 8 2 6 1',
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertSame('Sathi Mondal', $parsed['name']);
        $this->assertSame('01756808261', $parsed['phone']);
    }

    #[Test]
    public function compact_name_phone_address_blob_still_reads_the_person(): void
    {
        $conversation = $this->conversation('PSID_NILA', 'Someone Else');
        $this->inbound($conversation, [
            "Nila\n01627237432\nBanani, Dhaka\nSilk Kurti",
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertSame('Nila', $parsed['name']);
        $this->assertSame('01627237432', $parsed['phone']);
    }

    #[Test]
    public function order_confirm_chatter_is_not_used_as_the_customer_name(): void
    {
        $conversation = $this->conversation('PSID_CHATTER_NAME', 'Hamida Ahmed');
        $this->inbound($conversation, [
            'Order confirm kore rakhen',
            '01883104511',
        ]);

        $parsed = app(ChannelOrderParser::class)->parseConversation($conversation->fresh(['messages']));

        $this->assertNull($parsed['name']);
        $this->assertSame('01883104511', $parsed['phone']);
    }

    /**
     * @param  list<string>  $bodies
     */
    private function inbound(ChannelConversation $conversation, array $bodies): void
    {
        foreach ($bodies as $offset => $body) {
            ChannelMessage::query()->create([
                'channel_conversation_id' => $conversation->id,
                'external_message_id' => 'm_'.$conversation->id.'_'.$offset,
                'direction' => ChannelMessage::DIRECTION_INBOUND,
                'body' => $body,
                'sent_at' => now()->subMinutes(count($bodies) - $offset),
            ]);
        }
    }

    private function conversation(string $psid, string $customerName): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => $customerName,
            'last_inbound_at' => now(),
        ]);
    }
}
