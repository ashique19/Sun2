<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AnalyticsService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Analytics')]
#[Layout('components.layouts.admin')]
class AdminAnalytics extends Component
{
    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
    }

    public function render(AnalyticsService $analytics)
    {
        $year = (int) now('Asia/Dhaka')->year;
        $overview = $analytics->yearOverview($year);
        $ovd = $analytics->orderedVsDeliveredByMonth($year);

        return view('livewire.admin.admin-analytics', [
            'year' => $year,
            'tiles' => [
                [
                    'title' => 'Profit & loss',
                    'blurb' => 'Month-by-month revenue, direct cost, expenses, and profit (by order created date).',
                    'route' => 'admin.analytics.pnl',
                    'stat' => '৳'.number_format($overview['revenue'], 0).' collected · '.$year,
                ],
                [
                    'title' => 'All orders with costs',
                    'blurb' => 'Order-by-order revenue, COGS, packaging, courier, and contribution P/L.',
                    'route' => 'admin.analytics.orders-with-costs',
                    'stat' => 'Editable packaging · courier · COGS',
                ],
                [
                    'title' => 'Ordered vs delivered',
                    'blurb' => 'Of orders created that month, how many delivered and how much collected.',
                    'route' => 'admin.analytics.ordered-delivered',
                    'stat' => number_format($ovd['totals']['ordered_count']).' ordered · '.number_format($ovd['totals']['delivered_count']).' delivered',
                ],
                [
                    'title' => 'Revenue by category',
                    'blurb' => 'Delivered line revenue split by product category each month.',
                    'route' => 'admin.analytics.category-revenue',
                    'stat' => 'By created month · '.$year,
                ],
                [
                    'title' => 'Investor pitch',
                    'blurb' => 'Yearly traction, YoY growth, unit economics, and channel mix for fundraising.',
                    'route' => 'admin.analytics.investor-pitch',
                    'stat' => 'By created date · vs prior year',
                ],
            ],
        ]);
    }
}
