<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductDescriptionHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductDescriptionEditorTest extends TestCase
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
    public function product_edit_uses_rich_text_editors_instead_of_raw_textareas(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Gold Hoop',
            'slug' => 'gold-hoop',
            'price' => 900,
            'is_published' => true,
            'description' => '<p>Shiny hoops.</p>',
            'description_bn' => '<p>ঝলমলে হুপ।</p>',
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('English description')
            ->assertSee('Bangla description')
            ->assertSeeHtml('data-rich-text-editor="description"')
            ->assertSeeHtml('data-rich-text-editor="description_bn"')
            ->assertDontSeeHtml('wire:model.live="description"')
            ->assertDontSeeHtml('wire:model.live="description_bn"');
    }

    #[Test]
    public function save_sanitizes_description_html(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Pearl Stud',
            'slug' => 'pearl-stud',
            'price' => 500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('description', '<p>Safe</p><script>alert(1)</script><p onclick="x()">Click</p>')
            ->set('description_bn', '<p>নিরাপদ</p><script>bad()</script>')
            ->call('save');

        $product->refresh();

        $this->assertSame('<p>Safe</p><p>Click</p>', $product->description);
        $this->assertSame('<p>নিরাপদ</p>', $product->description_bn);
        $this->assertStringNotContainsString('<script', strtolower((string) $product->description));
        $this->assertStringNotContainsString('onclick', strtolower((string) $product->description));
    }

    #[Test]
    public function product_description_html_sanitizer_strips_unsafe_markup(): void
    {
        $clean = ProductDescriptionHtml::sanitize(
            '<p>Ok</p><script>x()</script><p onclick="y">Safe</p><a href="javascript:alert(1)">x</a>'
        );

        $this->assertStringContainsString('<p>Ok</p>', $clean);
        $this->assertStringContainsString('<p>Safe</p>', $clean);
        $this->assertStringNotContainsString('<script', strtolower($clean));
        $this->assertStringNotContainsString('onclick', strtolower($clean));
        $this->assertStringNotContainsString('javascript:', strtolower($clean));
    }
}
