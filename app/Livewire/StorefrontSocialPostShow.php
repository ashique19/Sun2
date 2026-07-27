<?php

namespace App\Livewire;

use App\Models\SocialPost;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Illuminate\Support\Str;

#[Layout('components.layouts.app')]
class StorefrontSocialPostShow extends Component
{
    public SocialPost $socialPost;

    public function mount(SocialPost $socialPost): void
    {
        abort_unless($socialPost->status === SocialPost::STATUS_PUBLISHED, 404);

        $this->socialPost = $socialPost;
    }

    public function render()
    {
        $post = SocialPost::query()
            ->with([
                'publications',
                'products' => fn ($q) => $q->with([
                    'category:id,slug,name',
                    'images' => fn ($img) => $img->orderBy('sort_order'),
                ]),
            ])
            ->findOrFail($this->socialPost->id);

        $facebookPub = $post->facebookPublication();
        $primaryCategory = $post->products->first()?->category;

        return view('livewire.storefront-social-post', [
            'post' => $post,
            'facebookPub' => $facebookPub,
            'primaryCategory' => $primaryCategory,
        ])->title(trim((string) Str::limit(strip_tags($post->body), 65, '')) ?: (string) config('seo.default_title'));
    }
}

