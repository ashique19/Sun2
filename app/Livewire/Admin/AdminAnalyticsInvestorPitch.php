<?php

namespace App\Livewire\Admin;

use App\Models\InvestorPitchShare;
use App\Services\Admin\InvestorPitchAnalyticsService;
use App\Services\Admin\InvestorPitchShareService;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Schema;
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

    public string $shareLabel = '';

    public string $sharePassword = '';

    public int $shareDays = 7;

    public ?string $createdShareUrl = null;

    public ?string $createdSharePassword = null;

    public ?string $createdShareLabel = null;

    public ?string $createdShareExpiresAt = null;

    public function mount(InvestorPitchAnalyticsService $analytics): void
    {
        AdminAccess::ensureStaffAdmin();

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function selectYear(int $year): void
    {
        if ($year < 2000) {
            return;
        }

        $this->year = $year;
    }

    public function createShare(InvestorPitchShareService $shares): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->validate([
            'shareLabel' => ['nullable', 'string', 'max:120'],
            'sharePassword' => ['required', 'string', 'min:6', 'max:100'],
            'shareDays' => ['required', 'integer', 'min:1', 'max:90'],
        ], [
            'sharePassword.required' => 'Enter a password for the recipient.',
            'sharePassword.min' => 'Use at least 6 characters for the share password.',
        ]);

        try {
            $created = $shares->create(
                $this->shareLabel,
                $this->sharePassword,
                $this->shareDays,
                auth()->id(),
            );
        } catch (\Throwable $e) {
            report($e);

            $this->addError(
                'sharePassword',
                'Could not create the share link. Confirm the investor_pitch_shares table exists, then try again.',
            );

            return;
        }

        $this->createdShareUrl = $created['url'];
        $this->createdSharePassword = $created['plain_password'];
        $this->createdShareLabel = $created['share']->label;
        $this->createdShareExpiresAt = $created['share']->expires_at
            ->timezone('Asia/Dhaka')
            ->format('d M Y, h:i A');

        $this->shareLabel = '';
        $this->sharePassword = '';
        $this->shareDays = 7;
        $this->resetErrorBag();
    }

    public function copyCreatedShareUrl(): void
    {
        if ($this->createdShareUrl === null || $this->createdShareUrl === '') {
            return;
        }

        $this->js('window.sunCopyText('.json_encode($this->createdShareUrl, JSON_THROW_ON_ERROR).')');
    }

    public function copyCreatedSharePassword(): void
    {
        if ($this->createdSharePassword === null || $this->createdSharePassword === '') {
            return;
        }

        $this->js('window.sunCopyText('.json_encode($this->createdSharePassword, JSON_THROW_ON_ERROR).')');
    }

    public function dismissCreatedShare(): void
    {
        $this->createdShareUrl = null;
        $this->createdSharePassword = null;
        $this->createdShareLabel = null;
        $this->createdShareExpiresAt = null;
    }

    public function revokeShare(int $shareId): void
    {
        AdminAccess::ensureStaffAdmin();

        $share = InvestorPitchShare::query()->findOrFail($shareId);
        $share->revoke();

        if ($this->createdShareUrl === $share->url()) {
            $this->dismissCreatedShare();
        }
    }

    public function render(InvestorPitchAnalyticsService $analytics)
    {
        $years = $analytics->availableYears();

        if (! in_array($this->year, $years, true)) {
            $years[] = $this->year;
            rsort($years);
        }

        $shares = collect();

        try {
            $shares = InvestorPitchShare::query()
                ->with('creator:id,name')
                ->latest()
                ->limit(20)
                ->get();
        } catch (\Throwable $e) {
            report($e);
        }

        return view('livewire.admin.admin-analytics-investor-pitch', [
            'years' => $years,
            'deck' => $analytics->deck($this->year),
            'shares' => $shares,
            'sharesUnavailable' => $shares->isEmpty()
                && ! Schema::hasTable('investor_pitch_shares'),
        ]);
    }
}
