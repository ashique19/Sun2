<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Analytics detail')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsDetail extends Component
{
    public int $year;

    public int $month;

    public string $metric;

    public function mount(int $year, int $month, string $metric): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($month < 1 || $month > 12 || ! in_array(strtolower($metric), AnalyticsService::METRICS, true)) {
            abort(404);
        }

        $this->year = $year;
        $this->month = $month;
        $this->metric = strtolower($metric);
    }

    public function render(AnalyticsService $analytics)
    {
        $detail = $analytics->metricDetail($this->year, $this->month, $this->metric);

        return view('livewire.admin.admin-analytics-detail', [
            'summary' => $detail['summary'],
            'orders' => $detail['orders'],
            'periodLabel' => $detail['label'],
        ]);
    }
}
