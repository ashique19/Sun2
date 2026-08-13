<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AnalyticsYearCompareService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Analytics · Compare years')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsCompare extends Component
{
    #[Url]
    public string $metric = 'profit';

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->normalizeMetric();
    }

    public function updatedMetric(): void
    {
        $this->normalizeMetric();
    }

    public function render(AnalyticsYearCompareService $compare)
    {
        $chart = $compare->compare($this->metric);

        return view('livewire.admin.admin-analytics-compare', [
            'chart' => $chart,
            'metrics' => AnalyticsYearCompareService::METRIC_META,
        ]);
    }

    private function normalizeMetric(): void
    {
        $metric = strtolower($this->metric);
        if (! in_array($metric, AnalyticsYearCompareService::METRICS, true)) {
            $metric = 'profit';
        }
        $this->metric = $metric;
    }
}
