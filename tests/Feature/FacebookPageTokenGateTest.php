<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminFacebookTokenGate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FacebookPageTokenGateTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function gate_prompts_when_token_missing(): void
    {
        config([
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->assertSee('Facebook Page token needs attention')
            ->assertSee('Open Graph API Explorer')
            ->assertSee('Paste User or Page access token');
    }

    #[Test]
    public function gate_prompts_when_graph_reports_expired_token(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'expired-token',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->assertSee('Session has expired')
            ->assertSee('Save token');
    }

    #[Test]
    public function saving_valid_token_persists_setting_and_clears_prompt(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'old-token',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me*' => Http::sequence()
                ->push([
                    'error' => ['message' => 'Session has expired.', 'type' => 'OAuthException', 'code' => 190],
                ], 400)
                ->push([
                    'id' => 'page-1',
                    'name' => 'Sundoritoma',
                ], 200)
                ->push([
                    'id' => 'page-1',
                    'name' => 'Sundoritoma',
                ], 200),
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->assertSee('Facebook Page token needs attention')
            ->set('tokenInput', 'new-valid-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true)
            ->assertDontSee('Facebook Page token needs attention')
            ->assertDontSee('Paste User or Page access token');

        $this->assertSame('new-valid-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('new-valid-token', app(FacebookPageTokenService::class)->token());
    }

    #[Test]
    public function saving_user_token_exchanges_to_never_expiring_page_token(): void
    {
        config([
            'facebook.app_id' => 'app-123',
            'facebook.messenger.app_secret' => 'secret-456',
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/debug_token')) {
                $input = (string) ($request['input_token'] ?? '');

                if ($input === 'short-lived-user-token') {
                    return Http::response([
                        'data' => [
                            'app_id' => 'app-123',
                            'type' => 'USER',
                            'is_valid' => true,
                            'expires_at' => now()->addHour()->timestamp,
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [
                        'app_id' => 'app-123',
                        'type' => 'PAGE',
                        'is_valid' => true,
                        'expires_at' => 0,
                    ],
                ], 200);
            }

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'long-lived-user-token',
                    'token_type' => 'bearer',
                    'expires_in' => 5184000,
                ], 200);
            }

            if (str_contains($url, '/page-1')) {
                return Http::response([
                    'access_token' => 'never-expiring-page-token',
                    'name' => 'Sundoritoma',
                    'id' => 'page-1',
                ], 200);
            }

            if (str_contains($url, '/me')) {
                return Http::response([
                    'id' => 'page-1',
                    'name' => 'Sundoritoma',
                ], 200);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL: '.$url]], 500);
        });

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->set('tokenInput', 'short-lived-user-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true)
            ->assertSee('never-expiring');

        $this->assertSame('never-expiring-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('never-expiring-page-token', app(FacebookPageTokenService::class)->token());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/oauth/access_token')
                && $request['grant_type'] === 'fb_exchange_token'
                && $request['fb_exchange_token'] === 'short-lived-user-token';
        });
    }

    #[Test]
    public function saving_page_token_that_never_expires_keeps_it(): void
    {
        config([
            'facebook.app_id' => 'app-123',
            'facebook.messenger.app_secret' => 'secret-456',
            'facebook.messenger.page_access_token' => '',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/debug_token*' => Http::response([
                'data' => [
                    'app_id' => 'app-123',
                    'type' => 'PAGE',
                    'is_valid' => true,
                    'expires_at' => 0,
                ],
            ], 200),
            'https://graph.facebook.com/v25.0/me*' => Http::response([
                'id' => 'page-1',
                'name' => 'Sundoritoma',
            ], 200),
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->set('tokenInput', 'stable-page-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true);

        $this->assertSame('stable-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
    }

    #[Test]
    public function valid_token_hides_gate_until_it_expires(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'good-token',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v25.0/me*' => Http::response([
                'id' => 'page-1',
                'name' => 'Sundoritoma',
            ], 200),
        ]);

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->assertDontSee('Facebook Page token needs attention')
            ->assertDontSee('Paste User or Page access token')
            ->assertDontSee('Update token')
            ->assertDontSee('Save token');
    }

    #[Test]
    public function db_override_wins_over_env_config(): void
    {
        config([
            'facebook.messenger.page_access_token' => 'env-token',
            'facebook.messenger.page_id' => 'page-1',
        ]);

        Setting::putValue(FacebookPageTokenService::SETTING_KEY, 'db-token', 'facebook');

        $this->assertSame('db-token', app(FacebookPageTokenService::class)->token());
    }
}
