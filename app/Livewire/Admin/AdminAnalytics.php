<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Analytics')]
#[Layout('components.layouts.admin')]
class AdminAnalytics extends Component
{
    #[Url]
    public int $year = 0;

    #[Url]
    public ?int $month = null;

    public function mount(AnalyticsService $analytics): void
    {
        AdminAccess::ensureStaffAdmin();

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }

        if ($this->month !== null && ($this->month < 1 || $this->month > 12)) {
            $this->month = null;
        }
    }

    public function updatedYear(): void
    {
        $this->month = null;
    }

    public function selectMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            return;
        }

        $this->month = $month;
    }

    public function clearMonth(): void
    {
        $this->month = null;
    }

    public function openMetric(string $metric): void
    {
        if ($this->month === null || ! in_array($metric, AnalyticsService::METRICS, true)) {
            return;
        }

        $this->redirect(route('admin.analytics.detail', [
            'year' => $this->year,
            'month' => $this->month,
            'metric' => $metric,
        ]), navigate: true);
    }

    public function render(AnalyticsService $analytics)
    {
        $years = $analytics->availableYears();

        if (! in_array($this->year, $years, true)) {
            $this->year = $years[0] ?? (int) now('Asia/Dhaka')->year;
        }

        $yearOverview = $analytics->yearOverview($this->year);
        $monthBreakdown = $this->month
            ? $analytics->monthBreakdown($this->year, $this->month)
            : null;

        return view('livewire.admin.admin-analytics', [
            'years' => $years,
            'yearOverview' => $yearOverview,
            'monthBreakdown' => $monthBreakdown,
        ]);
    }
}
