<?php

namespace App\Livewire;

use App\Models\Page;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontPage extends Component
{
    public Page $page;

    public function mount(Page $page): void
    {
        $this->page = $page;
    }

    public function title(): string
    {
        return str($this->page->name)->headline()->toString().' - Sundoritoma';
    }

    public function render()
    {
        return view('livewire.storefront-page')
            ->title($this->title());
    }
}
