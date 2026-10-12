<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInbox;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Channels\ChannelMessageImageMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

/**
 * "Matching product…" must always end in a result or a visible error, whatever goes wrong
 * on the server (download, matcher crash, PHP fatal, concurrent run) or in the browser.
 */
class InboxImageMatchResilienceTest extends TestCase
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

    private function conversation(): ChannelConversation
    {
        return ChannelConversation::query()->create([
            'channel' => ChannelConversation::CHANNEL_MESSENGER,
            'external_user_id' => 'psid-resilience-'.uniqid(),
            'customer_name' => 'Resilience Customer',
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

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeMeta(ChannelMessage $message, array $meta, bool $checked = true): void
    {
        $message->forceFill([
            'matched_product_id' => null,
            'image_match_checked_at' => $checked ? now() : null,
            'image_match_meta' => ['v' => ChannelMessageImageMatchService::META_VERSION, 'rejected' => [], ...$meta],
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFor(mixed $component, ChannelMessage $message): array
    {
        return $component->get('inboundImageMatchState')[(string) $message->id];
    }

    #[Test]
    public function a_failed_download_is_a_final_visible_error_not_a_pending_photo(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/expired.jpg');

        $this->post(route('admin.inbox.image-match', $message))
            ->assertOk()
            ->assertJson([
                'status' => ChannelMessageImageMatchService::STATUS_FAILED,
                'error' => ChannelMessageImageMatchService::FAILURE_MESSAGES['download_failed'],
            ]);

        $message->refresh();
        $this->assertNotNull($message->image_match_checked_at, 'A failed attempt must not look "never matched" (that rendered as pending forever).');
        $this->assertSame('download_failed', $message->image_match_meta['error']);
        $this->assertSame(1, $message->image_match_meta['attempts']);
        $this->assertArrayNotHasKey('attempt_started_at', $message->image_match_meta);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('the Facebook link may have expired')
            ->assertSee('No match — search product')
            ->assertSeeHtml('wire:click.stop="retryInboundImageMatch('.$message->id.')"')
            ->assertDontSee('Matching product…');

        $this->assertSame('done', $this->stateFor($component, $message)['status']);
        $this->assertSame('failed', $this->stateFor($component, $message)['match_status']);
    }

    #[Test]
    public function a_matcher_crash_is_saved_as_a_server_error_and_answered_with_200(): void
    {
        $photo = $this->productPhotoBytes(201);
        $this->catalogProduct('Crash Ring', $photo);
        $url = 'https://cdn.example.test/crash.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);
        $this->mock(ProductImageMatcher::class, fn ($mock) => $mock->shouldReceive('match')->andThrow(new RuntimeException('GD ran out of pixels')));

        $this->actingAs($this->adminUser());
        $message = $this->photoMessage($this->conversation(), $url);

        $this->post(route('admin.inbox.image-match', $message))
            ->assertOk()
            ->assertJsonPath('status', ChannelMessageImageMatchService::STATUS_FAILED)
            ->assertJsonPath('error', 'Server error while matching. GD ran out of pixels');

        $this->assertSame('server_error', $message->fresh()->image_match_meta['error']);
    }

    #[Test]
    public function a_concurrent_match_of_the_same_photo_answers_busy_so_the_browser_retries(): void
    {
        config(['channels.image_match.lock_wait_seconds' => 0]);
        $this->actingAs($this->adminUser());
        $message = $this->photoMessage($this->conversation(), 'https://cdn.example.test/busy.jpg');
        Http::fake();

        $lock = Cache::lock('channel-image-match:'.$message->id, 60);
        $this->assertTrue($lock->get());

        $this->post(route('admin.inbox.image-match', $message))
            ->assertStatus(202)
            ->assertJsonPath('status', 'busy');
        Http::assertNothingSent();

        $lock->release();
    }

    #[Test]
    public function failed_photos_retry_automatically_a_bounded_number_of_times(): void
    {
        $service = app(ChannelMessageImageMatchService::class);
        $message = $this->photoMessage($this->conversation(), 'https://cdn.example.test/retry.jpg');

        $this->storeMeta($message, ['status' => 'failed', 'error' => 'download_failed', 'attempts' => 1, 'retry_after' => now()->addMinutes(10)->getTimestamp()]);
        $this->assertFalse($service->awaitsMatch($message->fresh()));

        $this->travel(11)->minutes();
        $this->assertTrue($service->awaitsMatch($message->fresh()));

        $this->storeMeta($message, ['status' => 'failed', 'error' => 'download_failed', 'attempts' => ChannelMessageImageMatchService::MAX_AUTO_ATTEMPTS, 'retry_after' => 0]);
        $this->assertFalse($service->awaitsMatch($message->fresh()), 'After the automatic attempts the error stays until staff retry.');
    }

    #[Test]
    public function staff_retry_queues_a_fresh_request_with_a_new_url(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/retry-me.jpg');
        $this->storeMeta($message, ['status' => 'failed', 'error' => 'timeout', 'attempts' => 3, 'retry_after' => 0]);

        $component = Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('PHP time limit');

        $component->call('retryInboundImageMatch', $message->id);

        $state = $this->stateFor($component, $message);
        $this->assertSame('pending', $state['status']);
        $this->assertStringContainsString('attempt=1.0', $state['match_url']);
        $component->assertSeeHtml('wire:poll.10s="refreshInboundImageMatches"');

        $this->post($state['match_url'])->assertOk()->assertJsonPath('status', ChannelMessageImageMatchService::STATUS_FAILED);

        $component->call('refreshInboundImageMatches')->assertSee('the Facebook link may have expired');
        $this->assertSame(1, $message->fresh()->image_match_meta['attempts']);
    }

    #[Test]
    public function an_attempt_killed_by_php_limits_turns_into_an_error_after_the_stall_window(): void
    {
        config(['channels.image_match.stall_seconds' => 60]);
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/killed.jpg');
        Http::fake();

        $component = Livewire::test(AdminInbox::class)->set('selectedConversationId', $conversation->id);
        $this->storeMeta($message, ['attempts' => 1, 'attempt_started_at' => now()->getTimestamp()], checked: false);

        $component->call('refreshInboundImageMatches');
        $this->assertSame('pending', $this->stateFor($component, $message)['status']);

        $this->travel(61)->seconds();
        $component->call('refreshInboundImageMatches')
            ->assertSee('Matching didn’t finish on the server')
            ->assertDontSee('Matching product…');
    }

    #[Test]
    public function a_photo_the_browser_never_started_matching_turns_into_an_error(): void
    {
        config(['channels.image_match.stall_seconds' => 60]);
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/never.jpg');
        Http::fake();

        $component = Livewire::test(AdminInbox::class)->set('selectedConversationId', $conversation->id);
        $message->forceFill(['image_match_checked_at' => null, 'image_match_meta' => null])->save();

        $component->call('refreshInboundImageMatches');
        $this->assertSame('pending', $this->stateFor($component, $message)['status']);

        $this->travel(61)->seconds();
        $component->call('refreshInboundImageMatches')->assertSee('Matching never started in this browser');
    }

    #[Test]
    public function stale_results_keep_showing_and_refresh_silently_instead_of_spinning(): void
    {
        $photo = $this->productPhotoBytes(202);
        $product = $this->catalogProduct('Stale Candidate', $photo);
        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $message = $this->photoMessage($conversation, 'https://cdn.example.test/stale.jpg');
        Http::fake();

        $component = Livewire::test(AdminInbox::class)->set('selectedConversationId', $conversation->id);
        $this->storeMeta($message, [
            'status' => ChannelMessageImageMatchService::STATUS_SUGGESTED,
            'stamp' => 'an-older-index',
            'candidates' => [['product_id' => $product->id, 'score' => 0.8, 'source' => 'catalog', 'source_id' => 0, 'region' => null]],
        ]);

        $component->call('refreshInboundImageMatches')->assertSee('Is it?')->assertDontSee('Matching product…');
        $state = $this->stateFor($component, $message);
        $this->assertSame('done', $state['status']);
        $this->assertArrayHasKey('refresh_url', $state);

        ProductImage::query()->create(['product_id' => $product->id, 'path' => '/img/unsigned.jpg', 'sort_order' => 1]);
        Cache::forget('image-match-index-health');

        $component->call('refreshInboundImageMatches');
        $this->assertArrayNotHasKey('refresh_url', $this->stateFor($component, $message), 'No re-check churn while catalog photos are still unsigned.');
    }

    #[Test]
    public function inbox_tells_admins_when_the_photo_index_is_incomplete_or_cannot_be_cached(): void
    {
        $photo = $this->productPhotoBytes(203);
        $this->catalogProduct('Signed Ring', $photo);
        $this->catalogProduct('Unsigned Ring', $this->productPhotoBytes(204), signed: false);
        $url = 'https://cdn.example.test/notice.jpg';
        Http::fake([$url => Http::response($photo, 200, ['Content-Type' => 'image/jpeg'])]);
        config(['channels.image_match.lazy_index_budget_ms' => 0]);

        $this->actingAs($this->adminUser());
        $conversation = $this->conversation();
        $this->photoMessage($conversation, $url);

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('Photo matching index is incomplete: 1 of 2 catalog photos indexed')
            ->assertSee('Build photo index')
            ->assertSeeHtml(route('admin.image-hashes'))
            ->assertDontSee('storage/framework/cache');

        Cache::put('image-match-index-write-failed', true, 60);
        Cache::forget('image-match-index-health');

        Livewire::test(AdminInbox::class)
            ->call('selectConversation', $conversation->id)
            ->assertSee('can’t write to storage/framework/cache');
    }

    #[Test]
    public function fatal_errors_map_to_admin_readable_reasons(): void
    {
        $this->assertSame('timeout', ChannelMessageImageMatchService::failureReasonForFatal('Maximum execution time of 30 seconds exceeded'));
        $this->assertSame('memory', ChannelMessageImageMatchService::failureReasonForFatal('Allowed memory size of 134217728 bytes exhausted'));
        $this->assertSame('server_error', ChannelMessageImageMatchService::failureReasonForFatal('Call to undefined function'));
        $this->assertStringContainsString('PHP time limit', ChannelMessageImageMatchService::failureMessage('timeout'));
    }

    #[Test]
    public function the_match_script_is_loaded_on_every_admin_page_for_wire_navigate(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.image-hashes'))
            ->assertOk()
            ->assertSee('inbox-image-match-timeout-ms', false)
            ->assertSee('inbox-image-match-retries', false)
            ->assertSee('assets/inbox-image-match-', false);
    }
}
