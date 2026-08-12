<?php

namespace App\Livewire\Admin;

use App\Models\InvestorPitchShare;
use App\Services\Admin\InvestorPitchAnalyticsService;
use App\Services\Admin\InvestorPitchShareService;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Analytics · Investor pitch')]
#[Layout('components.layouts.admin')]
class AdminAnalyticsInvestorPitch extends Component
{
    public const CREATED_SHARE_SESSION_KEY = 'investor_pitch_share_created';

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

        $this->hydrateCreatedShareFromSession();
    }

    public function selectYear(int $year): void
    {
        if ($year < 2000) {
            return;
        }

        $this->year = $year;
    }

    public function createShare(): void
    {
        AdminAccess::ensureStaffAdmin();

        try {
            $this->validate([
                'shareLabel' => ['nullable', 'string', 'max:120'],
                'sharePassword' => ['required', 'string', 'min:6', 'max:100'],
                'shareDays' => ['required', 'integer', 'min:1', 'max:90'],
            ], [
                'sharePassword.required' => 'Enter a password for the recipient.',
                'sharePassword.min' => 'Use at least 6 characters for the share password.',
            ]);

            $created = app(InvestorPitchShareService::class)->create(
                $this->shareLabel,
                $this->sharePassword,
                $this->shareDays,
                auth()->id(),
            );

            session()->flash(self::CREATED_SHARE_SESSION_KEY, [
                'url' => $created['url'],
                'password' => $created['plain_password'],
                'label' => $created['share']->label,
                'expires_at' => $created['share']->expires_at
                    ->timezone('Asia/Dhaka')
                    ->format('d M Y, h:i A'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            $this->addError(
                'sharePassword',
                'Could not create the share link: '.$e->getMessage(),
            );

            return;
        }

        // Full redirect avoids re-rendering the heavy deck in the same Livewire
        // request (that path was returning a 500 and Livewire's empty black dialog).
        $this->redirect(route('admin.analytics.investor-pitch', [
            'year' => $this->year,
        ]));
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

        try {
            app(InvestorPitchShareService::class)->ensureSchema();

            $share = InvestorPitchShare::query()->findOrFail($shareId);
            $share->revoke();

            if ($this->createdShareUrl === $share->url()) {
                $this->dismissCreatedShare();
            }
        } catch (\Throwable $e) {
            report($e);

            $this->addError(
                'sharePassword',
                'Could not revoke the share link: '.$e->getMessage(),
            );
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
        $sharesUnavailable = false;

        try {
            app(InvestorPitchShareService::class)->ensureSchema();

            $shares = InvestorPitchShare::query()
                ->with('creator:id,name')
                ->latest()
                ->limit(20)
                ->get();
        } catch (\Throwable $e) {
            report($e);
            $sharesUnavailable = ! Schema::hasTable('investor_pitch_shares');
        }

        return view('livewire.admin.admin-analytics-investor-pitch', [
            'years' => $years,
            'deck' => $analytics->deck($this->year),
            'shares' => $shares,
            'sharesUnavailable' => $sharesUnavailable,
        ]);
    }

    private function hydrateCreatedShareFromSession(): void
    {
        $flash = session()->pull(self::CREATED_SHARE_SESSION_KEY);

        if (! is_array($flash)) {
            return;
        }

        $this->createdShareUrl = isset($flash['url']) ? (string) $flash['url'] : null;
        $this->createdSharePassword = isset($flash['password']) ? (string) $flash['password'] : null;
        $this->createdShareLabel = array_key_exists('label', $flash) && $flash['label'] !== null
            ? (string) $flash['label']
            : null;
        $this->createdShareExpiresAt = isset($flash['expires_at']) ? (string) $flash['expires_at'] : null;
    }
}
