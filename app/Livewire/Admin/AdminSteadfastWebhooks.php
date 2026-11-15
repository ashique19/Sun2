<?php

namespace App\Livewire\Admin;

use App\Services\Couriers\SteadfastWebhookLogService;
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

    public function render(SteadfastWebhookLogService $webhookLogs)
    {
        AdminAccess::ensureStaffAdmin();

        return view('livewire.admin.admin-steadfast-webhooks', [
            'logs' => $webhookLogs->paginate(),
        ]);
    }
}
