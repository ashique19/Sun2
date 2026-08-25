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
            ->assertSeeHtml('h-11 w-11')
            ->assertSeeHtml('@pointerdown="startOverlayTextDrag($event)"')
            ->assertSeeHtml('@pointerdown.stop="startOverlayTextResize($event)"')
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
            ->assertSeeHtml('data-priced-stamp-stage')
            ->assertSeeHtml('startDrag($event)')
            ->assertSeeHtml('startResize($event)')
            ->assertSeeHtml("snap('center')")
            ->assertSee('Drag the price stamp');
    }
}
