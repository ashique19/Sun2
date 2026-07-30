<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Analytics · Revenue by category')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsCategoryRevenue extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(AnalyticsService $analytics): void
    {
        AdminAccess::ensureStaffAdmin();

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function render(AnalyticsService $analytics)
    {
        $years = $analytics->availableYears();

        if (! in_array($this->year, $years, true)) {
            $years[] = $this->year;
            rsort($years);
        }

        return view('livewire.admin.admin-analytics-category-revenue', [
            'years' => $years,
            'report' => $analytics->revenueByCategoryByMonth($this->year),
        ]);
    }
}
