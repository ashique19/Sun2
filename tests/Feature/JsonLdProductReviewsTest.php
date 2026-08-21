<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\JsonLd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JsonLdProductReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Gold Jhumka',
            'slug' => 'gold-jhumka',
            'sku' => 'GJ-1',
            'price' => 1200,
            'purchase_price' => 500,
            'stock_quantity' => 5,
            'is_published' => true,
            'display_order' => 0,
            'rating_avg' => 0,
            'review_count' => 0,
        ], $overrides));
    }

    #[Test]
    public function product_without_reviews_omits_aggregate_rating_and_review(): void
    {
        $schema = JsonLd::product($this->product());

        $this->assertSame('Product', $schema['@type']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
    }

    #[Test]
    public function product_with_approved_reviews_includes_aggregate_rating_and_review(): void
    {
        $product = $this->product([
            'rating_avg' => 4.5,
            'review_count' => 2,
        ]);
        $author = User::factory()->create(['name' => 'Ayesha']);

        ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $author->id,
            'rating' => 5,
            'title' => 'Loved it',
            'body' => 'Beautiful finish and fast delivery.',
            'status' => 'approved',
        ]);
        ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => null,
            'rating' => 4,
            'title' => null,
            'body' => 'Good quality for the price.',
            'status' => 'approved',
        ]);
        ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $author->id,
            'rating' => 1,
            'title' => 'Pending',
            'body' => 'Should not appear in schema.',
            'status' => 'pending',
        ]);

        $schema = JsonLd::product($product->fresh());

        $this->assertArrayHasKey('aggregateRating', $schema);
        $this->assertSame('AggregateRating', $schema['aggregateRating']['@type']);
        $this->assertSame('4.5', $schema['aggregateRating']['ratingValue']);
        $this->assertSame(2, $schema['aggregateRating']['reviewCount']);

        $this->assertArrayHasKey('review', $schema);
        $this->assertCount(2, $schema['review']);
        $this->assertSame('Review', $schema['review'][0]['@type']);
        $this->assertSame('Ayesha', $schema['review'][0]['author']['name']);
        $this->assertSame('5', $schema['review'][0]['reviewRating']['ratingValue']);
        $this->assertSame('Loved it', $schema['review'][0]['name']);
        $this->assertSame('Customer', $schema['review'][1]['author']['name']);
        $this->assertArrayNotHasKey('name', $schema['review'][1]);
    }

    #[Test]
    public function published_product_page_emits_review_json_ld_when_approved(): void
    {
        $product = $this->product([
            'rating_avg' => 5.0,
            'review_count' => 1,
        ]);
        $author = User::factory()->create(['name' => 'Nusrat']);

        ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $author->id,
            'rating' => 5,
            'title' => 'Perfect',
            'body' => 'Exactly as pictured.',
            'status' => 'approved',
        ]);

        $response = $this->get(route('product.show', $product));

        $response->assertOk();
        $response->assertSee('"@type":"AggregateRating"', false);
        $response->assertSee('"@type":"Review"', false);
        $response->assertSee('"reviewBody":"Exactly as pictured."', false);
        $response->assertSee('"name":"Nusrat"', false);
    }
}
