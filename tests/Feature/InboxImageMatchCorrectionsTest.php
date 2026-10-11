<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Livewire\Admin\AdminOrderForm;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\ProductImageMatchMemory;
use App\Models\User;
use App\Services\Admin\ProductImageSignatureService;
use App\Services\Channels\ChannelConversationService;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

class InboxImageMatchCorrectionsTest extends TestCase
{
    use ProductPhotoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['channels.ai_draft.image_min_bytes' => 100]);
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function conversation(string $psid = 'psid-corrections'): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => $psid,
            'customer_name' => 'Correction Customer',
            'last_inbound_at' => now(),
        ]);
    }

    private function photoMessage(ChannelConversation $conversation, string $url): ChannelMessage
    {
        return ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => $url,
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function not_this_rejects_the_auto_match_and_never_reattaches_it(): void
    {
        $photo = $this->productPhotoBytes(81);
        $wrong = $this->catalogProduct('Auto Picked Ring', $photo);
        $url = 'https://cdn.example.test/reject.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Auto Picked Ring')
            ->assertSee('Not this')
            ->call('rejectMatchedProduct', $message->id)
            ->assertSet('mappingMessageId', $message->id)
            ->assertSet('mappingMode', 'tag')
            ->assertSee('No match — search product');

        $message->refresh();
        $this->assertNull($message->matched_product_id);
        $this->assertSame([$wrong->id], $message->image_match_meta['rejected']);

        $service = app(ChannelMessageImageMatchService::class);
        $service->resolve($message, force: true);
        $this->assertNull($message->fresh()->matched_product_id);
        $this->assertSame([], $service->candidateRows($message->fresh()));

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertDontSeeHtml('wire:click="sendMatchedProductPriceReply('.$message->id.')"');
    }

    #[Test]
    public function staff_tag_teaches_the_matcher_a_photo_the_catalog_does_not_contain(): void
    {
        $product = $this->catalogProduct('Studio Shot Necklace', $this->productPhotoBytes(82));
        $this->catalogProduct('Other Necklace', $this->productPhotoBytes(83));

        $customerPhoto = $this->productPhotoBytes(84);
        $firstUrl = 'https://cdn.example.test/customer-photo.jpg';
        $secondUrl = 'https://cdn.example.test/customer-screenshot.png';
        Http::fake([
            $firstUrl => Http::response($customerPhoto, 200, ['Content-Type' => 'image/jpeg']),
            $secondUrl => Http::response($this->phoneScreenshotBytes($customerPhoto), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $first = $this->photoMessage($conversation, $firstUrl);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $first->id)
            ->call('tagMatchedProduct', $product->id)
            ->assertSee('Studio Shot Necklace');

        $this->assertSame($product->id, $first->fresh()->matched_product_id);
        $learned = ProductImageMatchMemory::query()
            ->where('hash_kind', ProductImageMatchMemory::KIND_SIGNATURE)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($learned?->signature);

        $second = $this->photoMessage($this->conversation('psid-corrections-2'), $secondUrl);
        $match = app(ChannelMessageImageMatchService::class)->bestAutoMatch($second);

        $this->assertSame($product->id, $match['product_id'] ?? null, 'A screenshot of the tagged photo should match the learned signature.');
        $this->assertSame('visual', $match['strategy']);
        $this->assertSame('memory', $second->fresh()->image_match_meta['candidates'][0]['source']);
    }

    #[Test]
    public function one_click_suggestion_tags_the_product_and_records_it_as_manual(): void
    {
        $photo = $this->productPhotoBytes(85);
        $this->catalogProduct('Twin Bangle Gold', $photo);
        $chosen = $this->catalogProduct('Twin Bangle Rose', $photo);
        $url = 'https://cdn.example.test/twin.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Is it?')
            ->call('tagSuggestedProduct', $message->id, $chosen->id)
            ->assertSet('statusMessage', 'Tagged “Twin Bangle Rose” on this photo.')
            ->assertSee('+Order');

        $state = $component->get('inboundImageMatchState')[(string) $message->id];
        $this->assertSame($chosen->id, $state['product_id']);
        $this->assertSame('manual', $state['strategy']);
        $this->assertSame(ChannelMessageImageMatchService::STATUS_TAGGED, $message->fresh()->image_match_meta['status']);
    }

    #[Test]
    public function only_candidates_above_the_suggest_score_are_offered(): void
    {
        $close = $this->catalogProduct('Close Candidate', $this->productPhotoBytes(95));
        $far = $this->catalogProduct('Far Candidate', $this->productPhotoBytes(96));
        $message = $this->photoMessage($this->conversation(), 'https://cdn.example.test/stored.jpg');
        $message->forceFill([
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                'v' => ChannelMessageImageMatchService::META_VERSION,
                'status' => ChannelMessageImageMatchService::STATUS_SUGGESTED,
                'candidates' => [
                    ['product_id' => $close->id, 'score' => 0.8, 'source' => 'catalog', 'source_id' => 1, 'region' => null],
                    ['product_id' => $far->id, 'score' => 0.7, 'source' => 'catalog', 'source_id' => 2, 'region' => null],
                ],
                'rejected' => [],
            ],
        ])->save();

        $rows = app(ChannelMessageImageMatchService::class)->candidateRows($message->fresh());

        $this->assertSame([$close->id], array_column($rows, 'product_id'));
        $this->assertSame(80.0, $rows[0]['match_percent']);
    }

    #[Test]
    public function crop_region_on_the_original_chat_image_matches_and_is_learned_on_tag(): void
    {
        $photo = $this->productPhotoBytes(86);
        $product = $this->catalogProduct('Cropped Earrings', $photo);
        $this->catalogProduct('Other Earrings', $this->productPhotoBytes(87));
        $url = 'https://cdn.example.test/crop-region.png';
        Http::fake([$url => Http::response($this->phoneScreenshotBytes($photo), 200, ['Content-Type' => 'image/png'])]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);
        $region = ['left' => 0.0, 'top' => 160 / 780, 'width' => 1.0, 'height' => 360 / 780];

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->call('matchProductFromCropRegion', $region)
            ->assertSet('mappingImageMatchError', null)
            ->assertSee('Cropped Earrings');

        $this->assertSame($product->id, $component->get('mappingImageMatches')[0]['product_id']);
        $this->assertEqualsWithDelta(360 / 780, $component->get('mappingCropRegion')['height'], 0.001);

        $component->call('selectMappingImageMatch', $product->id)->assertSee('Tagged');

        $learned = ProductImageMatchMemory::query()->where('hash_kind', ProductImageMatchMemory::KIND_SIGNATURE)->sole();
        $this->assertSame($product->id, (int) $learned->product_id);
        $this->assertSame('region', app(ProductImageSignatureService::class)->unpack($learned->signature)[0]['variant']);
    }

    #[Test]
    public function crop_region_without_an_area_asks_staff_to_select_one(): void
    {
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/none.jpg');
        Http::fake(['*' => Http::response('', 404)]);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->call('openTagProductOnImage', $message->id)
            ->call('matchProductFromCropRegion', ['left' => 0.2, 'top' => 0.2, 'width' => 0, 'height' => 0])
            ->assertSet('mappingImageMatchError', 'Select an area of the chat image first.')
            ->assertSet('mappingImageMatches', []);
    }

    #[Test]
    public function unmatched_photos_are_rechecked_after_the_catalog_changes(): void
    {
        $photo = $this->productPhotoBytes(88);
        $this->catalogProduct('Unrelated Clip', $this->productPhotoBytes(89));
        $url = 'https://cdn.example.test/new-arrival.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('No match — search product');
        $this->assertNull($message->fresh()->matched_product_id);

        $added = $this->catalogProduct('New Arrival Clip', $photo);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('New Arrival Clip');
        $this->assertSame($added->id, $message->fresh()->matched_product_id);
    }

    #[Test]
    public function image_match_endpoint_resolves_one_photo_for_staff(): void
    {
        $photo = $this->productPhotoBytes(90);
        $product = $this->catalogProduct('Endpoint Ring', $photo);
        $url = 'https://cdn.example.test/endpoint.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, $url);
        $text = ChannelMessage::query()->create([
            'channel_conversation_id' => $conversation->id,
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'body' => 'hello',
            'sent_at' => now(),
        ]);

        $this->post(route('admin.inbox.image-match', $message))->assertRedirect();

        $customer = User::factory()->create();
        $this->actingAs($customer)->post(route('admin.inbox.image-match', $message))->assertForbidden();

        $this->actingAs($this->adminUser());
        $this->post(route('admin.inbox.image-match', $text))->assertNotFound();
        $this->post(route('admin.inbox.image-match', $message))
            ->assertOk()
            ->assertExactJson([
                'message_id' => $message->id,
                'status' => ChannelMessageImageMatchService::STATUS_MATCHED,
                'product_id' => $product->id,
            ]);
    }

    #[Test]
    public function new_inbound_photos_are_matched_right_after_the_webhook_response(): void
    {
        config(['channels.image_match.prewarm_on_receive' => true]);
        $photo = $this->productPhotoBytes(91);
        $product = $this->catalogProduct('Prewarmed Ring', $photo);
        Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $conversation = $this->conversation();
        $message = app(ChannelConversationService::class)->storeMessage($conversation, [
            'external_message_id' => 'm_prewarm',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=prewarm',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $this->assertNull($message->fresh()->image_match_checked_at);

        $this->app->terminate();

        $this->assertSame($product->id, $message->fresh()->matched_product_id);
        $this->assertNotNull($message->fresh()->image_match_checked_at);
    }

    #[Test]
    public function prewarm_is_skipped_when_disabled(): void
    {
        config(['channels.image_match.prewarm_on_receive' => false]);
        $photo = $this->productPhotoBytes(92);
        $this->catalogProduct('Not Prewarmed Ring', $photo);
        Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);

        $message = app(ChannelConversationService::class)->storeMessage($this->conversation(), [
            'external_message_id' => 'm_no_prewarm',
            'direction' => ChannelMessage::DIRECTION_INBOUND,
            'media_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=no-prewarm',
            'media_mime' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $this->app->terminate();

        $this->assertNull($message->fresh()->image_match_checked_at);
        Http::assertNothingSent();
    }

    #[Test]
    public function order_form_adds_a_confident_pasted_image_match_and_lists_ambiguous_ones(): void
    {
        $this->actingAs($this->adminUser());

        $photo = $this->productPhotoBytes(93);
        $product = $this->catalogProduct('Pasted Pendant', $photo);
        $twinPhoto = $this->productPhotoBytes(94);
        $this->catalogProduct('Twin Pendant One', $twinPhoto);
        $this->catalogProduct('Twin Pendant Two', $twinPhoto);

        Livewire::test(AdminOrderForm::class)
            ->set('pastedImage', UploadedFile::fake()->createWithContent('paste.png', $this->phoneScreenshotBytes($photo)))
            ->assertSet('showImageMatchModal', false)
            ->assertSet('lines.'.$product->id.'.product_id', $product->id);

        $component = Livewire::test(AdminOrderForm::class)
            ->set('pastedImage', UploadedFile::fake()->createWithContent('paste.jpg', $twinPhoto))
            ->assertSet('showImageMatchModal', true)
            ->assertSet('lines', [])
            ->assertSee('Twin Pendant One')
            ->assertSee('Twin Pendant Two');

        $this->assertCount(2, $component->get('imageMatches'));
    }
}
