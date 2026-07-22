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
                $this->statusMessage = __('storefront.password_reset_email');
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
            $this->addError('identifier', __('storefront.account_not_found'));

            return;
        }

        try {
            app(PasswordResetOtpService::class)->send($this->identifier);
            $this->step = 'phone-otp';
            $this->otp = '';
            $this->statusMessage = __('storefront.otp_sent_display', [
                'phone' => PhoneNumber::display($this->identifier),
            ]);
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
            $this->formError = __('storefront.account_not_found');

            return;
        }

        if (! $otpService->verify($this->identifier, $this->otp)) {
            $this->addError('otp', __('storefront.otp_invalid'));

            return;
        }

        $user->update(['password' => $this->password]);
        $this->statusMessage = __('storefront.password_updated_login');
        $this->step = 'done';
    }

    public function render()
    {
        return view('livewire.storefront-forgot-password');
    }
}
