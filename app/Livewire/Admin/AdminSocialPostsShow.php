<?php

namespace App\Livewire\Admin;

use App\Models\SocialPost;
use App\Services\Social\MetaGraphSocialPublisher;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminSocialPostsShow extends Component
{
    public SocialPost $socialPost;

    public ?string $message = null;

    public function mount(SocialPost $socialPost): void
    {
        $this->socialPost = $socialPost;
    }

    public function republish(MetaGraphSocialPublisher $publisher): void
    {
        AdminSocialPostsShow::ensureCanRepublish();

        $publisher->publish($this->socialPost->fresh(['products', 'publications']));

        $this->socialPost->refresh();
        $this->message = 'Re-publish attempted.';
    }

    private static function ensureCanRepublish(): void
    {
        // Route middleware already restricts access, but keep this as a safe guard.
        abort_unless(auth()->check() && auth()->user()->hasAnyRole(['admin', 'dev']), 403);
    }

    public function render()
    {
        $post = SocialPost::query()
            ->with([
                'publications',
                'products' => fn ($q) => $q->with([
                    'category:id,slug,name',
                    'images' => fn ($img) => $img->orderBy('sort_order'),
                ])->orderBy('social_post_products.sort_order'),
            ])
            ->findOrFail($this->socialPost->id);

        return view('livewire.admin.admin-social-post-show', [
            'post' => $post,
        ]);
    }
}

