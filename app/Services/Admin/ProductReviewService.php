<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductReview;

class ProductReviewService
{
    public function approve(ProductReview $review): void
    {
        $review->update(['status' => 'approved']);
        $this->syncProductRatings($review->product_id);
    }

    public function reject(ProductReview $review): void
    {
        $review->update(['status' => 'rejected']);
        $this->syncProductRatings($review->product_id);
    }

    public function syncProductRatings(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);

        $stats = ProductReview::query()
            ->approved()
            ->where('product_id', $productId)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();

        $product->update([
            'rating_avg' => round((float) ($stats->avg_rating ?? 0), 2),
            'review_count' => (int) ($stats->total ?? 0),
        ]);
    }
}
