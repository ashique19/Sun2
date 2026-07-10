<?php

namespace App\Livewire;

use App\Models\User;
use App\Rules\BangladeshMobile;
use App\Services\Auth\PasswordResetOtpService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Forgot Password - Sundoritoma')]
#[Layout('components.layouts.app')]
class StorefrontForgotPassword extends Component
{
    public string $identifier = '';

    public string $step = 'request';

    public string $otp = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $statusMessage = null;

    public ?string $formError = null;

    public function sendReset(): void
    {
        $this->formError = null;
        $this->statusMessage = null;

        if (filter_var($this->identifier, FILTER_VALIDATE_EMAIL)) {
            $this->validate(['identifier' => ['required', 'email', 'exists:users,email']]);

            $status = Password::sendResetLink(['email' => $this->identifier]);

            if ($status === Password::RESET_LINK_SENT) {
                $this->statusMessage = 'Password reset link sent to your email.';
                $this->step = 'email-sent';

                return;
            }

            $this->formError = __($status);

            return;
        }

        $this->validate([
            'identifier' => ['required', 'string', new BangladeshMobile],
        ]);

        $user = User::findByPhone($this->identifier);

        if (! $user) {
            $this->addError('identifier', 'No account found with this mobile number.');

            return;
        }

        try {
            app(PasswordResetOtpService::class)->send($this->identifier);
            $this->step = 'phone-otp';
            $this->otp = '';
            $this->statusMessage = 'OTP sent to '.PhoneNumber::display($this->identifier).'.';
        } catch (\Throwable $e) {
            $this->formError = $e->getMessage();
        }
    }

    public function resetWithOtp(PasswordResetOtpService $otpService): void
    {
        $this->formError = null;

        $this->validate([
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::findByPhone($this->identifier);

        if (! $user) {
            $this->formError = 'Account not found.';

            return;
        }

        if (! $otpService->verify($this->identifier, $this->otp)) {
            $this->addError('otp', 'Invalid or expired OTP.');

            return;
        }

        $user->update(['password' => $this->password]);
        $this->statusMessage = 'Password updated successfully. You can now log in.';
        $this->step = 'done';
    }

    public function render()
    {
        return view('livewire.storefront-forgot-password');
    }
}
