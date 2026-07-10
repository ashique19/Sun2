<?php

namespace App\Livewire\Admin;

use App\Models\ProductReview;
use App\Services\Admin\ProductReviewService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Reviews')]
#[Layout('components.layouts.admin')]
class AdminReviews extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function approve(int $reviewId, ProductReviewService $reviews): void
    {
        $review = ProductReview::query()->findOrFail($reviewId);
        $reviews->approve($review);
    }

    public function reject(int $reviewId, ProductReviewService $reviews): void
    {
        $review = ProductReview::query()->findOrFail($reviewId);
        $reviews->reject($review);
    }

    public function render()
    {
        $reviews = ProductReview::query()
            ->with(['product:id,name,slug', 'user:id,name'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(20);

        return view('livewire.admin.admin-reviews', [
            'reviews' => $reviews,
        ]);
    }
}
