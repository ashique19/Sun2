<?php

namespace App\Livewire;

use App\Services\Admin\InvestorPitchAnalyticsService;
use App\Support\InvestorPitchShare;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PublicInvestorPitch extends Component
{
    public string $token = '';

    public int $year = 0;

    public string $password = '';

    public bool $unlocked = false;

    public function mount(string $token, InvestorPitchAnalyticsService $analytics): void
    {
        abort_unless(InvestorPitchShare::isConfigured(), 404);
        abort_unless(InvestorPitchShare::tokenMatches($token), 404);

        $this->token = $token;
        $this->unlocked = InvestorPitchShare::isUnlocked($token);

        $years = $analytics->availableYears();
        $current = (int) now('Asia/Dhaka')->year;

        if ($this->year <= 0 || ! in_array($this->year, $years, true)) {
            $this->year = in_array($current, $years, true) ? $current : ($years[0] ?? $current);
        }
    }

    public function unlock(): void
    {
        abort_unless(InvestorPitchShare::tokenMatches($this->token), 404);

        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! InvestorPitchShare::passwordMatches($this->password)) {
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        InvestorPitchShare::unlock($this->token);
        $this->unlocked = true;
        $this->password = '';
        $this->resetErrorBag();
    }

    public function lock(): void
    {
        InvestorPitchShare::lock($this->token);
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
        if (! $this->unlocked) {
            return view('livewire.public-investor-pitch', [
                'years' => [],
                'deck' => null,
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
        ])
            ->title('Investor pitch')
            ->layoutData([
                'seoRobots' => 'noindex, nofollow',
            ]);
    }
}
