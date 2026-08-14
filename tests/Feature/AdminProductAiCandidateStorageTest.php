<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductAiCandidateStorageTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Gold Necklace',
            'slug' => 'gold-necklace-storage',
            'price' => 1500,
            'is_published' => true,
        ]);
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    #[Test]
    public function generate_stores_candidate_binary_on_disk_not_in_livewire_state(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')->once()->andReturn([
            'mime' => 'image/png',
            'base64' => $this->tinyPngBase64(),
        ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $admin = $this->adminUser();
        $this->actingAs($admin);
        $product = $this->product();

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiPrompt', 'Clean white background')
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png')
            ->assertCount('aiCandidates', 1)
            ->assertSet('aiGenerateError', null);

        $candidate = $component->get('aiCandidates')[0];
        $this->assertArrayNotHasKey('base64', $candidate);
        $this->assertSame(1, $candidate['version']);

        $binPath = storage_path('app/private/ai-candidates/'.$admin->id.'/'.$candidate['id'].'.bin');
        $this->assertFileExists($binPath);
        $this->assertSame(base64_decode($this->tinyPngBase64(), true), File::get($binPath));

        $this->get(route('admin.products.ai-candidate', $candidate['id']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function promote_reads_disk_candidate_and_clears_temp_files(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $product = $this->product();
        $id = 'candidate-disk-1';

        $dir = storage_path('app/private/ai-candidates/'.$admin->id);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', base64_decode($this->tinyPngBase64(), true));
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai-1.png',
                'version' => 1,
            ]])
            ->call('promoteAiCandidate', $id)
            ->assertCount('aiCandidates', 0)
            ->assertSet('message', 'AI image added to product gallery.');

        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertFileDoesNotExist($dir.'/'.$id.'.bin');
        $this->assertFileDoesNotExist($dir.'/'.$id.'.json');
    }

    #[Test]
    public function update_candidate_rewrites_disk_file_without_keeping_base64_in_state(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $product = $this->product();
        $id = 'candidate-edit-1';

        $dir = storage_path('app/private/ai-candidates/'.$admin->id);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', 'old');
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai.png',
                'version' => 1,
            ]])
            ->call('updateAiCandidate', $id, 'image/jpeg', $this->tinyPngBase64())
            ->assertSet('aiGenerateError', null);

        $candidate = $component->get('aiCandidates')[0];
        $this->assertArrayNotHasKey('base64', $candidate);
        $this->assertSame(2, $candidate['version']);
        $this->assertSame('image/jpeg', $candidate['mime']);
        $this->assertSame(base64_decode($this->tinyPngBase64(), true), File::get($dir.'/'.$id.'.bin'));
    }
}
