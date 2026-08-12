<?php

namespace App\Livewire;

use App\Models\InvestorPitchShare;
use App\Services\Admin\InvestorPitchAnalyticsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PublicInvestorPitch extends Component
{
    public string $token = '';

    public int $year = 0;

    public string $password = '';

    public bool $unlocked = false;

    public bool $expired = false;

    public bool $revoked = false;

    public function mount(string $token, InvestorPitchAnalyticsService $analytics): void
    {
        $share = InvestorPitchShare::query()->where('token', $token)->first();
        abort_unless($share !== null, 404);

        $this->token = $token;
        $this->revoked = $share->isRevoked();
        $this->expired = ! $this->revoked && $share->isExpired();

        if ($share->isAccessible() && InvestorPitchShare::isUnlocked($token)) {
            $this->unlocked = true;
        }

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function unlock(): void
    {
        $share = $this->shareOrAbort();

        if (! $share->isAccessible()) {
            $this->revoked = $share->isRevoked();
            $this->expired = ! $this->revoked && $share->isExpired();
            $this->unlocked = false;

            return;
        }

        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $share->passwordMatches($this->password)) {
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        $share->unlockSession();
        $this->unlocked = true;
        $this->password = '';
        $this->resetErrorBag();
    }

    public function lock(): void
    {
        $share = InvestorPitchShare::query()->where('token', $this->token)->first();
        $share?->lockSession();
        $this->unlocked = false;
        $this->password = '';
    }

    public function selectYear(int $year): void
    {
        abort_unless($this->unlocked, 403);

        if ($year < 2000) {
            return;
        }

        $this->year = $year;
    }

    public function render(InvestorPitchAnalyticsService $analytics)
    {
        $share = InvestorPitchShare::query()->where('token', $this->token)->first();
        abort_unless($share !== null, 404);

        $this->revoked = $share->isRevoked();
        $this->expired = ! $this->revoked && $share->isExpired();

        if (! $share->isAccessible()) {
            $this->unlocked = false;
        }

        if (! $this->unlocked || ! $share->isAccessible()) {
            return view('livewire.public-investor-pitch', [
                'years' => [],
                'deck' => null,
                'share' => $share,
            ])
                ->title('Investor pitch')
                ->layoutData([
                    'seoRobots' => 'noindex, nofollow',
                ]);
        }

        $years = $analytics->availableYears();

        if (! in_array($this->year, $years, true)) {
            $years[] = $this->year;
            rsort($years);
        }

        return view('livewire.public-investor-pitch', [
            'years' => $years,
            'deck' => $analytics->deck($this->year),
            'share' => $share,
        ])
            ->title('Investor pitch')
            ->layoutData([
                'seoRobots' => 'noindex, nofollow',
            ]);
    }

    private function shareOrAbort(): InvestorPitchShare
    {
        $share = InvestorPitchShare::query()->where('token', $this->token)->first();
        abort_unless($share !== null, 404);

        return $share;
    }
}
