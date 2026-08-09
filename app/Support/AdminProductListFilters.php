<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Session;

class AdminProductListFilters
{
    public const SESSION_KEY = 'admin.products.list_filters';

    public function __construct(
        public string $search = '',
        public string $category = '',
        public string $published = '',
        public string $priceMin = '',
        public string $priceMax = '',
    ) {}

    /**
     * @param  array{
     *     search?: string,
     *     category?: string,
     *     published?: string,
     *     priceMin?: string,
     *     priceMax?: string
     * }  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            search: trim((string) ($values['search'] ?? '')),
            category: trim((string) ($values['category'] ?? '')),
            published: trim((string) ($values['published'] ?? '')),
            priceMin: trim((string) ($values['priceMin'] ?? '')),
            priceMax: trim((string) ($values['priceMax'] ?? '')),
        );
    }

    public static function recall(): self
    {
        $stored = Session::get(self::SESSION_KEY);

        if (! is_array($stored)) {
            return new self;
        }

        return self::fromArray($stored);
    }

    public function remember(): void
    {
        Session::put(self::SESSION_KEY, $this->toArray());
    }

    /**
     * @return array{
     *     search: string,
     *     category: string,
     *     published: string,
     *     priceMin: string,
     *     priceMax: string
     * }
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'category' => $this->category,
            'published' => $this->published,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
        ];
    }

    /**
     * Query-string parameters for the admin products index route.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        return array_filter(
            $this->toArray(),
            static fn (string $value): bool => $value !== '',
        );
    }

    public function isActive(): bool
    {
        return $this->queryParameters() !== [];
    }

    public function activeCount(): int
    {
        return count($this->queryParameters());
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function apply(Builder $query): Builder
    {
        $priceMin = $this->normalizedPriceBound($this->priceMin);
        $priceMax = $this->normalizedPriceBound($this->priceMax);

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        return $query
            ->when($this->search !== '', fn (Builder $q) => $q->searchTerm($this->search, includePrice: false))
            ->when($priceMin !== null, fn (Builder $q) => $q->where('price', '>=', $priceMin))
            ->when($priceMax !== null, fn (Builder $q) => $q->where('price', '<=', $priceMax))
            ->when($this->category !== '', fn (Builder $q) => $q->where('category_id', $this->category))
            ->when($this->published === '1', fn (Builder $q) => $q->where('is_published', true))
            ->when($this->published === '0', fn (Builder $q) => $q->where('is_published', false));
    }

    private function normalizedPriceBound(string $value): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', trim($value)) ?? '';

        if ($digits === '' || ! is_numeric($digits)) {
            return null;
        }

        return max(0, (float) $digits);
    }
}
