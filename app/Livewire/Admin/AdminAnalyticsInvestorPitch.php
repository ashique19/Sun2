<?php

namespace App\Livewire\Admin;

use App\Services\Admin\InvestorPitchAnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Analytics · Investor pitch')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsInvestorPitch extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(InvestorPitchAnalyticsService $analytics): void
    {
        AdminAccess::ensureStaffAdmin();

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function refreshDeck(): void
    {
        // Manual refresh — re-query on next render.
    }

    public function render(InvestorPitchAnalyticsService $analytics)
    {
        $years = $analytics->availableYears();

        if (! in_array($this->year, $years, true)) {
            $years[] = $this->year;
            rsort($years);
        }

        return view('livewire.admin.admin-analytics-investor-pitch', [
            'years' => $years,
            'deck' => $analytics->deck($this->year),
        ]);
    }
}
