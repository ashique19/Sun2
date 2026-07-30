<?php

namespace App\Livewire\Admin;

use App\Models\SocialPost;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Social Post')]
#[Layout('components.layouts.admin')]
class AdminSocialPostsEdit extends Component
{
    public SocialPost $socialPost;

    public string $body = '';

    public bool $showOnHomepage = true;

    public string $status = SocialPost::STATUS_PUBLISHED;

    public function mount(SocialPost $socialPost): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->socialPost = $socialPost;
        $this->body = (string) $socialPost->body;
        $this->showOnHomepage = (bool) $socialPost->show_on_homepage;
        $this->status = (string) $socialPost->status;
    }

    public function save(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->validate([
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'showOnHomepage' => ['boolean'],
            'status' => ['required', 'in:'.implode(',', [
                SocialPost::STATUS_DRAFT,
                SocialPost::STATUS_PUBLISHED,
                SocialPost::STATUS_FAILED,
            ])],
        ]);

        $this->socialPost->update([
            'body' => $this->body,
            'show_on_homepage' => $this->showOnHomepage,
            'status' => $this->status,
        ]);

        $this->redirect(route('admin.social-posts.show', $this->socialPost), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-social-posts-edit', [
            'post' => $this->socialPost->loadMissing([
                'products' => fn ($q) => $q->orderBy('social_post_products.sort_order'),
            ]),
        ]);
    }
}
