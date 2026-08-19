<?php

namespace Tests\Feature;

use App\Livewire\StorefrontForgotPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorefrontForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_can_reset_password_with_phone_otp(): void
    {
        $user = User::factory()->create([
            'phone' => '01627237432',
            'password' => 'old-password',
        ]);

        Livewire::test(StorefrontForgotPassword::class)
            ->set('identifier', '01627237432')
            ->call('sendReset')
            ->assertSet('step', 'phone-otp')
            ->assertSet('resetPhone', '01627237432')
            ->set('otp', '123456')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('resetWithOtp')
            ->assertSet('step', 'done')
            ->assertSee(__('storefront.password_updated_login'));

        $user->refresh();

        $this->assertTrue(password_verify('new-password-1', (string) $user->password));
    }

    #[Test]
    public function reset_uses_persisted_phone_when_identifier_is_corrupted(): void
    {
        User::factory()->create([
            'phone' => '01711112222',
        ]);

        Livewire::test(StorefrontForgotPassword::class)
            ->set('identifier', '01711112222')
            ->call('sendReset')
            ->assertSet('resetPhone', '01711112222')
            ->set('identifier', '123456')
            ->set('otp', '123456')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('resetWithOtp')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');
    }

    #[Test]
    public function reset_with_otp_fails_when_phone_was_never_confirmed(): void
    {
        Cache::put('password_reset_otp:1711112222', [
            'code' => '123456',
            'attempts' => 0,
        ], now()->addMinutes(10));

        Livewire::test(StorefrontForgotPassword::class)
            ->set('identifier', '01711112222')
            ->set('step', 'phone-otp')
            ->set('otp', '123456')
            ->set('password', 'new-password-1')
            ->set('password_confirmation', 'new-password-1')
            ->call('resetWithOtp')
            ->assertSet('formError', __('storefront.account_not_found'));
    }
}
