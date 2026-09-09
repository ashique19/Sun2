<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
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
            ->assertSeeHtml('role="group" aria-label="Text position"')
            ->assertSeeHtml('aria-label="Top left"')
            ->assertSeeHtml('aria-label="Center"')
            ->assertSeeHtml('class="flex gap-1.5"')
            ->assertDontSee('Text size (px)')
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

    #[Test]
    public function saved_image_edit_modal_uses_icon_position_tabs_including_center(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('aria-label="Text position"')
            ->assertSeeHtml('aria-label="Logo position"')
            ->assertSeeHtml(':aria-label="option.label"')
            ->assertSeeHtml(':aria-label="`Logo ${option.label}`"')
            ->assertSeeHtml('option.icon.x')
            ->assertSeeHtml('snapOverlayTextPosition')
            ->assertSeeHtml('startOverlayTextDrag')
            ->assertSeeHtml('startOverlayTextResize')
            ->assertSeeHtml('snapOverlayLogoPosition')
            ->assertSeeHtml('startOverlayLogoDrag')
            ->assertSeeHtml('startOverlayLogoResize')
            ->assertSeeHtml('data-text-overlay-stage')
            ->assertSeeHtml('data-overlay-image-frame')
            ->assertSeeHtml('min="12" max="200"')
            ->assertDontSeeHtml('name="overlay-text-position"')
            ->assertDontSeeHtml('name="overlay-logo-position"');

        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString("value: 'center'", $source);
        $this->assertStringContainsString("case 'center':", $source);
        $this->assertStringContainsString('overlayTextX', $source);
        $this->assertStringContainsString('overlayTextY', $source);
        $this->assertStringContainsString('overlayLogoX', $source);
        $this->assertStringContainsString('overlayLogoY', $source);
        $this->assertStringContainsString('snapOverlayTextPosition', $source);
        $this->assertStringContainsString('snapOverlayLogoPosition', $source);
        $this->assertStringContainsString('startOverlayTextDrag', $source);
        $this->assertStringContainsString('startOverlayLogoDrag', $source);
        $this->assertStringContainsString('startOverlayTextResize', $source);
        $this->assertStringContainsString('startOverlayLogoResize', $source);
        $this->assertStringContainsString('includeText: false', $source);
        $this->assertStringContainsString('includeLogo: false', $source);
        $this->assertStringContainsString('includeOverlayImage: false', $source);
        $this->assertStringContainsString('onOverlayImageSelected', $source);
        $this->assertStringContainsString('clearOverlayImage', $source);
        $this->assertStringContainsString('startOverlayImageDrag', $source);
        $this->assertStringContainsString('startOverlayImageResize', $source);
        $this->assertStringContainsString('drawOverlayImage', $source);
        $this->assertStringContainsString('beginOverlayGesture', $source);
        $this->assertStringContainsString('endOverlayGesture', $source);
        $this->assertStringContainsString('overlayGestureActive', $source);
        $this->assertStringContainsString("pointerType === 'touch'", $source);
        $this->assertStringContainsString('pricedImageStampEditor', $source);
        $this->assertStringContainsString('overlayImageFrameRect', $source);
        $this->assertStringContainsString("querySelector?.('[data-overlay-image-frame]')", $source);
        $this->assertStringContainsString('this.overlayImageFrameRect(event.currentTarget)', $source);
        $this->assertStringNotContainsString(
            "closest?.('[data-text-overlay-stage]');\n\n            if (! stage) {\n                return;\n            }\n\n            const rect = stage.getBoundingClientRect();",
            $source,
        );
    }

    #[Test]
    public function saved_image_edit_modal_uses_touch_stable_overlay_handles(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('touch-none')
            ->assertSeeHtml('h-10 w-10')
            ->assertSeeHtml('left-full top-full')
            ->assertSeeHtml('p-8 sm:p-4')
            ->assertSeeHtml('@pointerdown="startOverlayTextDrag($event)"')
            ->assertSeeHtml('@pointerdown.stop.prevent="startOverlayTextResize($event)"')
            ->assertDontSeeHtml('@pointermove="moveOverlayTextDrag($event)"')
            ->assertDontSeeHtml('@pointermove="moveOverlayImageDrag($event)"')
            ->assertSee('finger stays under the grab point');
    }

    #[Test]
    public function saved_image_edit_modal_exposes_image_overlay_upload_controls(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('data-overlay-image-controls')
            ->assertSeeHtml('Put image overlay')
            ->assertSeeHtml('onOverlayImageSelected($event)')
            ->assertSeeHtml('clearOverlayImage()')
            ->assertSeeHtml('startOverlayImageDrag($event)')
            ->assertSeeHtml('startOverlayImageResize($event)')
            ->assertSeeHtml('Remove image overlay')
            ->assertSeeHtml('accept="image/jpeg,image/png,image/webp,image/gif"');
    }

    #[Test]
    public function priced_image_modal_exposes_drag_resize_stamp_preview(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }
        $absolute = $absoluteDir.'/primary.jpg';
        $image = imagecreatetruecolor(320, 320);
        imagefilledrectangle($image, 0, 0, 319, 319, imagecolorallocate($image, 40, 100, 60));
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/primary.jpg',
            'alt' => 'Necklace',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('openPricedImageModal')
            ->assertSet('showPricedImageModal', true)
            ->assertSeeHtml('pricedImageStampEditor')
            ->assertSeeHtml('data-priced-stamp-editor-host')
            ->assertSeeHtml('wire:ignore')
            ->assertSeeHtml('data-priced-stamp-stage')
            ->assertSeeHtml('data-overlay-image-frame')
            ->assertSeeHtml('data-priced-image-modal-scroll')
            ->assertSeeHtml('data-priced-image-actions')
            ->assertSeeHtml('startDrag($event)')
            ->assertSeeHtml('startResize($event)')
            ->assertSeeHtml("snap('center')")
            ->assertSeeHtml('deletePricedImage()')
            ->assertSeeHtml('hasPricedImage ? \'Save & rebuild\' : \'Save & generate\'')
            ->assertSee('Drag the price stamp');
    }

    #[Test]
    public function priced_image_modal_keeps_save_and_delete_in_same_action_row(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
            'priced_image_path' => 'img/products-priced/1/demo.jpg',
            'priced_image_layout' => [
                'position' => 'top-left',
                'font' => 56,
                'x' => 0.12,
                'y' => 0.12,
            ],
        ]);

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->html();

        $this->assertStringContainsString('data-priced-image-actions', $html);
        $this->assertStringContainsString('data-priced-image-modal-scroll', $html);
        $this->assertStringContainsString('deletePricedImage()', $html);
        $this->assertStringContainsString("hasPricedImage ? 'Save & rebuild' : 'Save & generate'", $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-priced-stamp-editor-host[^>]*(?:flex-1|min-h-0)/',
            $html,
        );
    }

    #[Test]
    public function priced_image_modal_wraps_stamp_editor_in_wire_ignore(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Necklace Set',
            'slug' => 'necklace-set',
            'price' => 2500,
            'is_published' => true,
        ]);

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->html();

        $this->assertMatchesRegularExpression(
            '/wire:ignore[^>]*data-priced-stamp-editor-host|data-priced-stamp-editor-host[^>]*wire:ignore/s',
            $html,
        );
        $this->assertStringContainsString('pricedImageStampEditor', $html);
        $this->assertStringContainsString('wire:click="closePricedImageModal"', $html);
    }

    #[Test]
    public function apply_priced_image_stamp_layout_updates_all_props_in_one_call(): void
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
            ->call('applyPricedImageStampLayout', [
                'x' => 0.42,
                'y' => 0.63,
                'position' => 'custom',
                'font' => 72,
                'logo' => true,
                'logo_position' => 'custom',
                'logo_size' => 24,
                'logo_x' => 0.31,
                'logo_y' => 0.77,
            ])
            ->assertSet('pricedImageX', 0.42)
            ->assertSet('pricedImageY', 0.63)
            ->assertSet('pricedImagePosition', 'custom')
            ->assertSet('pricedImageFont', 72)
            ->assertSet('pricedImageLogo', true)
            ->assertSet('pricedImageLogoPosition', 'custom')
            ->assertSet('pricedImageLogoSize', 24)
            ->assertSet('pricedImageLogoX', 0.31)
            ->assertSet('pricedImageLogoY', 0.77);
    }

    #[Test]
    public function apply_priced_image_stamp_layout_centers_non_custom_positions(): void
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
            ->call('applyPricedImageStampLayout', [
                'x' => 0.42,
                'y' => 0.63,
                'position' => 'bottom-right',
                'font' => 64,
                'logo' => true,
                'logo_position' => 'top-left',
                'logo_size' => 20,
                'logo_x' => 0.31,
                'logo_y' => 0.77,
            ])
            ->assertSet('pricedImagePosition', 'bottom-right')
            ->assertSet('pricedImageX', 0.88)
            ->assertSet('pricedImageY', 0.88)
            ->assertSet('pricedImageLogoPosition', 'top-left')
            ->assertSet('pricedImageLogoX', 0.12)
            ->assertSet('pricedImageLogoY', 0.12)
            ->assertSet('pricedImageFont', 64)
            ->assertSet('pricedImageLogoSize', 20);
    }

    #[Test]
    public function priced_image_stamp_editor_syncs_via_batched_livewire_method(): void
    {
        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString('applyPricedImageStampLayout', $source);
        $this->assertStringContainsString('measureStageImage', $source);
        $this->assertStringContainsString('observeStageFrame', $source);
        $this->assertStringNotContainsString("await this.\$wire.set('pricedImageX'", $source);
        $this->assertStringNotContainsString("await this.\$wire.set('pricedImageY'", $source);
        $this->assertStringNotContainsString('/__agent_debug_log', $source);
    }
}
