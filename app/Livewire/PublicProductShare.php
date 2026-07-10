<?php

namespace App\Livewire;

use App\Models\ProductShareList;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PublicProductShare extends Component
{
    public string $token = '';

    public ?ProductShareList $share = null;

    public bool $expired = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->loadShare();
    }

    public function removeRow(string $key): void
    {
        if (! $this->canManageList()) {
            return;
        }

        $this->loadShare();

        if (! $this->share || $this->expired) {
            return;
        }

        $this->share->removeItem($key);
        $this->share->refresh();
    }

    public function render()
    {
        return view('livewire.public-product-share', [
            'items' => $this->share?->items ?? [],
            'canManage' => $this->canManageList(),
        ])->title($this->expired ? 'Link expired' : 'Product list');
    }

    private function canManageList(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasAnyRole(['admin', 'dev', 'moderator']);
    }

    private function loadShare(): void
    {
        $this->share = ProductShareList::query()->where('token', $this->token)->first();
        $this->expired = ! $this->share || $this->share->isExpired();
    }
}
