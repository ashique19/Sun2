<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductImageHashes;
use App\Models\ImageHashRun;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageEmbeddingService;
use App\Services\Admin\ProductImageHashRebuildService;
use App\Services\Admin\ProductImageMatchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\ProductPhotoFixtures;
use Tests\TestCase;

/**
 * Building the inbox photo index over HTTP (no artisan, queue worker or cron): small
 * resumable batches that survive PHP time limits.
 */
class ProductImageIndexBuilderTest extends TestCase
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

    private function unsignedImage(int $seed): ProductImage
    {
        return $this->catalogProduct('Unsigned '.$seed, $this->productPhotoBytes($seed), signed: false)->images->first();
    }

    #[Test]
    public function build_photo_index_button_signs_the_catalog_in_ticks_and_resumes(): void
    {
        $this->actingAs($this->adminUser());
        $images = [$this->unsignedImage(301), $this->unsignedImage(302), $this->unsignedImage(303)];

        $component = Livewire::test(AdminProductImageHashes::class)
            ->assertSee('Inbox photo matching index')
            ->assertSee('3 photo(s) not indexed yet')
            ->assertSee('needs no server access, queue worker or cron')
            ->call('buildMatchIndex');

        $run = ImageHashRun::query()->latest('id')->firstOrFail();
        for ($tick = 0; $tick < 10 && $run->fresh()->isActive(); $tick++) {
            // A fresh page load (tab closed and reopened) resumes the same run.
            Livewire::test(AdminProductImageHashes::class)->assertSet('activeRunId', $run->id)->call('tickRebuild');
        }

        $this->assertSame('completed', $run->fresh()->status);
        foreach ($images as $image) {
            $this->assertNotNull($image->fresh()->match_signature);
        }

        $component->call('tickRebuild')->assertSee('All readable catalog photos are indexed for inbox matching.');
        $this->assertSame(0, app(ProductImageMatchIndex::class)->health()['missing']);
        $this->assertNotEmpty(app(ProductImageMatchIndex::class)->entries());
    }

    #[Test]
    public function a_tick_killed_mid_image_skips_that_image_next_time(): void
    {
        $crashed = $this->unsignedImage(311);
        $next = $this->unsignedImage(312);
        $hashes = app(ProductImageHashRebuildService::class);

        $run = $hashes->start('admin');
        $run->update(['status' => 'running', 'meta' => [...$run->meta, 'in_flight_id' => $crashed->id]]);

        $this->assertTrue($hashes->processChunk($run->fresh()));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->failed);
        $this->assertSame(1, (int) $run->hashed_ok);
        $this->assertArrayNotHasKey('in_flight_id', $run->meta);
        $this->assertNull($crashed->fresh()->match_signature);
        $this->assertNotNull($next->fresh()->match_signature);

        $health = app(ProductImageMatchIndex::class)->health();
        $this->assertSame(1, $health['unreadable']);
        $this->assertSame(0, $health['missing'], 'An unreadable photo must not keep the inbox notice up forever.');
    }

    #[Test]
    public function images_missing_only_the_visual_signature_keep_their_other_hashes(): void
    {
        $image = $this->unsignedImage(321);
        $image->forceFill([
            'perceptual_hashes' => [['strategy' => 'full', 'hash' => 'keep-variants']],
            'dct_hash' => 'keep-dct',
            'embedding_vector' => array_fill(0, ProductImageEmbeddingService::DIMENSIONS, 0.5),
        ])->save();

        $hashes = app(ProductImageHashRebuildService::class);
        $hashes->runToCompletion($hashes->start('admin'));

        $image->refresh();
        $this->assertNotNull($image->match_signature);
        $this->assertSame('keep-dct', $image->dct_hash);
        $this->assertSame([['strategy' => 'full', 'hash' => 'keep-variants']], $image->perceptual_hashes);
    }

    #[Test]
    public function tick_budget_stays_inside_the_php_time_limit(): void
    {
        $original = ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '0');
            $this->assertSame(8, ProductImageHashRebuildService::tickBudgetSeconds());
            ini_set('max_execution_time', '30');
            $this->assertSame(8, ProductImageHashRebuildService::tickBudgetSeconds());
            ini_set('max_execution_time', '10');
            $this->assertSame(3, ProductImageHashRebuildService::tickBudgetSeconds());
            ini_set('max_execution_time', '4');
            $this->assertSame(2, ProductImageHashRebuildService::tickBudgetSeconds());
        } finally {
            ini_set('max_execution_time', (string) $original);
        }
    }

    #[Test]
    public function cron_url_works_in_budgeted_batches_and_reports_progress(): void
    {
        config(['products.image_hash_rebuild_token' => 'cron-secret']);
        $image = $this->unsignedImage(331);

        $this->get('/internal/product-image-hashes/rebuild?token=wrong')->assertForbidden();

        $this->get('/internal/product-image-hashes/rebuild?token=cron-secret')
            ->assertOk()
            ->assertJson(['ok' => true, 'done' => true, 'status' => 'completed']);

        $this->assertNotNull($image->fresh()->match_signature);
    }
}
