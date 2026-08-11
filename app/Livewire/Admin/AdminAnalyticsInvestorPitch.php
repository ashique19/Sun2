<?php

namespace App\Livewire\Admin;

use App\Services\Admin\InvestorPitchAnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Analytics · Investor pitch')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsInvestorPitch extends Component
{
    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
    }

    public function refreshDeck(): void
    {
        // Re-render only — poll / button triggers a fresh deck() query.
    }

    public function render(InvestorPitchAnalyticsService $analytics)
    {
        return view('livewire.admin.admin-analytics-investor-pitch', [
            'deck' => $analytics->deck(),
        ]);
    }
}
