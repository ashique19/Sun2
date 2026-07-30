<?php

namespace App\Livewire\Admin;

use App\Models\SocialPost;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Social Posts')]
#[Layout('components.layouts.admin')]
class AdminSocialPosts extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleShowOnHomepage(int $postId): void
    {
        AdminAccess::ensureStaffAdmin();

        $post = SocialPost::query()->find($postId);
        if (! $post) {
            return;
        }

        $post->update([
            'show_on_homepage' => ! (bool) $post->show_on_homepage,
        ]);
    }

    public function delete(int $postId): void
    {
        AdminAccess::ensureStaffAdmin();

        $post = SocialPost::query()->find($postId);
        if (! $post) {
            return;
        }

        $paths = array_filter([
            $post->thumbnail_path,
            $post->collage_path,
        ], static fn ($path) => is_string($path) && trim($path) !== '');

        $post->delete();

        foreach ($paths as $path) {
            $absolute = public_path(ltrim((string) $path, '/'));
            if (is_file($absolute)) {
                File::delete($absolute);
            }
        }
    }

    public function render()
    {
        $term = trim($this->search);

        $posts = SocialPost::query()
            ->withCount('products')
            ->with(['publications' => fn ($q) => $q->latest('id')->limit(3)])
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('body', 'like', '%'.$term.'%');
                    if (ctype_digit($term)) {
                        $inner->orWhere('id', (int) $term);
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.admin-social-posts', [
            'posts' => $posts,
        ]);
    }
}
