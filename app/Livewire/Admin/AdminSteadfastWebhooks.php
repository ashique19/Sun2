<?php

namespace App\Livewire\Admin;

use App\Models\CourierData;
use App\Services\Admin\SteadfastWebhookInboxService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Steadfast Webhooks')]
#[Layout('components.layouts.admin')]
class AdminSteadfastWebhooks extends Component
{
    use WithPagination;

    public function dismiss(int $entryId, SteadfastWebhookInboxService $webhookInbox): void
    {
        AdminAccess::ensureStaffAdmin();

        $entry = CourierData::query()->whereKey($entryId)->first();

        if (! $entry) {
            return;
        }

        $webhookInbox->dismiss($entry);
        $this->resetPage();
    }

    public function render(SteadfastWebhookInboxService $webhookInbox)
    {
        AdminAccess::ensureStaffAdmin();

        $entries = $webhookInbox->paginateIncoming();
        $summaries = [];

        foreach ($entries as $entry) {
            $summaries[$entry->id] = $webhookInbox->summary($entry);
        }

        return view('livewire.admin.admin-steadfast-webhooks', [
            'entries' => $entries,
            'summaries' => $summaries,
        ]);
    }
}
