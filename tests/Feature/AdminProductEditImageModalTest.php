<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductEditImageModalTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function edit_image_modal_is_gated_by_alpine_x_if_and_wire_ignore(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('wire:ignore')
            ->assertSeeHtml('x-if="editorOpen"')
            ->assertSeeHtml('x-if="savedEditorOpen"')
            ->assertSeeHtml('x-teleport="body"')
            ->assertSeeHtml('@click.self="onEditorOutside()"')
            ->assertSeeHtml('@click.stop="openEditor(index)"')
            ->assertDontSeeHtml('@click.outside="closeEditor()"')
            ->assertDontSeeHtml('x-show="editorOpen"')
            ->assertDontSeeHtml('x-show="savedEditorOpen"')
            ->assertDontSeeHtml('x-show="editorOpen" x-cloak');
    }

    #[Test]
    public function opening_priced_image_modal_still_keeps_edit_image_behind_x_if(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->assertSet('showPricedImageModal', true)
            ->assertSee('Priced image')
            ->assertSee('Text position')
            ->assertSee('Top left')
            ->assertSeeHtml('x-if="editorOpen"')
            ->assertDontSeeHtml('x-show="editorOpen"');
    }

    #[Test]
    public function livewire_updates_while_priced_image_open_do_not_switch_edit_image_to_x_show(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->set('pricedImagePosition', 'top-right')
            ->set('pricedImageFont', 64)
            ->assertSet('showPricedImageModal', true)
            ->assertSee('Priced image')
            ->assertSeeHtml('x-if="editorOpen"')
            ->assertDontSeeHtml('x-show="editorOpen"');
    }

    #[Test]
    public function ai_image_editor_modal_uses_conditional_mount_not_x_show(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->assertSet('showAiGenerateModal', true)
            ->assertSeeHtml('x-if="aiEditorOpen"')
            ->assertSeeHtml('x-teleport="body"')
            ->assertDontSeeHtml('x-show="aiEditorOpen"');
    }
}
