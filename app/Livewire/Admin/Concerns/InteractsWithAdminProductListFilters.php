<?php

namespace App\Livewire\Admin\Concerns;

use App\Support\AdminProductListFilters;

trait InteractsWithAdminProductListFilters
{
    public string $search = '';

    public string $category = '';

    public string $published = '';

    public string $priceMin = '';

    public string $priceMax = '';

    protected function hydrateAdminProductListFilters(): void
    {
        $filters = AdminProductListFilters::recall();

        $this->search = $filters->search;
        $this->category = $filters->category;
        $this->published = $filters->published;
        $this->priceMin = $filters->priceMin;
        $this->priceMax = $filters->priceMax;
    }

    protected function rememberAdminProductListFilters(): void
    {
        $this->currentAdminProductListFilters()->remember();
    }

    public function currentAdminProductListFilters(): AdminProductListFilters
    {
        return AdminProductListFilters::fromArray([
            'search' => $this->search,
            'category' => $this->category,
            'published' => $this->published,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
        ]);
    }

    public function updatedSearch(): void
    {
        $this->rememberAdminProductListFilters();
    }

    public function updatedCategory(): void
    {
        $this->rememberAdminProductListFilters();
    }

    public function updatedPublished(): void
    {
        $this->rememberAdminProductListFilters();
    }

    public function updatedPriceMin(): void
    {
        $this->rememberAdminProductListFilters();
    }

    public function updatedPriceMax(): void
    {
        $this->rememberAdminProductListFilters();
    }

    public function clearAdminProductListFilters(): void
    {
        $this->search = '';
        $this->category = '';
        $this->published = '';
        $this->priceMin = '';
        $this->priceMax = '';
        $this->rememberAdminProductListFilters();
    }
}
