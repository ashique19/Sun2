<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminFacebookTokenGate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
    public function expired_token_shows_when_it_expired(): void
    {
        $this->travelTo(Carbon::parse('2026-08-14 07:00:00', 'UTC'));

        config([
            'facebook.app_id' => 'app-123',
            'facebook.messenger.app_secret' => 'secret-456',
            'facebook.messenger.page_access_token' => 'expired-token',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/debug_token')) {
                return Http::response([
                    'data' => [
                        'app_id' => 'app-123',
                        'type' => 'PAGE',
                        'is_valid' => false,
                        'expires_at' => now()->subDays(2)->timestamp,
                    ],
                ], 200);
            }

            if (str_contains($url, '/me')) {
                return Http::response([
                    'error' => [
                        'message' => 'Error validating access token: Session has expired.',
                        'type' => 'OAuthException',
                        'code' => 190,
                    ],
                ], 400);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL: '.$url]], 500);
        });

        Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->assertSee('Session has expired')
            ->assertSee('Expired 12 Aug 2026, 01:00 PM')
            ->assertSee('2 days ago');
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

        $component = Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->set('tokenInput', 'short-lived-user-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true)
            ->assertDontSee('Connected as')
            ->assertSee('Never expires');

        $this->assertStringContainsString('never-expiring', $component->get('feedback'));

        $this->assertSame('never-expiring-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('never-expiring-page-token', app(FacebookPageTokenService::class)->token());
        $this->assertSame('long-lived-user-token', Setting::getValue(FacebookPageTokenService::USER_SETTING_KEY));
        $this->assertNotNull(Setting::getValue(FacebookPageTokenService::EXPIRES_SETTING_KEY));

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

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'should-not-be-saved',
                    'token_type' => 'bearer',
                    'expires_in' => 5184000,
                ], 200);
            }

            if (str_contains($url, '/debug_token')) {
                return Http::response([
                    'data' => [
                        'app_id' => 'app-123',
                        'type' => 'PAGE',
                        'is_valid' => true,
                        'expires_at' => 0,
                    ],
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
            ->set('tokenInput', 'stable-page-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true)
            ->assertSee('Never expires')
            ->assertDontSee('Connected as');

        $this->assertSame('stable-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/oauth/access_token'));
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
            ->assertDontSee('Save token')
            ->assertDontSee('Replace token')
            ->assertDontSee('Connected as')
            ->assertSee('Facebook token')
            ->set('showReplace', true)
            ->assertSee('Paste current User or Page access token')
            ->assertSee('Exchange & save')
            ->assertDontSee('Replace token')
            ->assertDontSee('Connected as');
    }

    #[Test]
    public function exchanging_an_expiring_page_token_saves_the_long_lived_token(): void
    {
        $this->travelTo(Carbon::parse('2026-08-14 07:00:00', 'UTC'));

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
                $expiresAt = $input === 'long-lived-page-token'
                    ? now()->addSeconds(5184000)->timestamp
                    : now()->addHour()->timestamp;

                return Http::response([
                    'data' => [
                        'app_id' => 'app-123',
                        'type' => 'PAGE',
                        'is_valid' => true,
                        'expires_at' => $expiresAt,
                    ],
                ], 200);
            }

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'long-lived-page-token',
                    'token_type' => 'bearer',
                    'expires_in' => 5184000,
                ], 200);
            }

            if (str_contains($url, '/me')) {
                $token = (string) ($request['access_token'] ?? '');

                return Http::response([
                    'id' => 'page-1',
                    'name' => 'Sundoritoma',
                ], $token === 'long-lived-page-token' ? 200 : 400);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL: '.$url]], 500);
        });

        $component = Livewire::actingAs($this->adminUser())
            ->test(AdminFacebookTokenGate::class)
            ->set('tokenInput', 'short-lived-page-token')
            ->call('saveToken')
            ->assertSet('feedbackOk', true)
            ->assertSee('Expires 13 Oct 2026, 01:00 PM')
            ->assertSee('in 60 days')
            ->assertDontSee('Connected as');

        $this->assertStringContainsString('long-lived Page token', $component->get('feedback'));

        $this->assertSame('long-lived-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('long-lived-page-token', app(FacebookPageTokenService::class)->token());
        $this->assertNotNull(Setting::getValue(FacebookPageTokenService::EXPIRES_SETTING_KEY));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/oauth/access_token')
                && $request['grant_type'] === 'fb_exchange_token'
                && $request['client_id'] === 'app-123'
                && $request['client_secret'] === 'secret-456'
                && $request['fb_exchange_token'] === 'short-lived-page-token';
        });
    }

    #[Test]
    public function refresh_command_reexchanges_stored_user_token(): void
    {
        config([
            'facebook.app_id' => 'app-123',
            'facebook.messenger.app_secret' => 'secret-456',
            'facebook.messenger.page_access_token' => 'old-page-token',
            'facebook.messenger.page_id' => 'page-1',
            'facebook.graph_version' => 'v25.0',
        ]);

        Setting::putValue(FacebookPageTokenService::SETTING_KEY, 'old-page-token', 'facebook');
        Setting::putValue(FacebookPageTokenService::USER_SETTING_KEY, 'stored-user-token', 'facebook');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/debug_token')) {
                return Http::response([
                    'data' => [
                        'app_id' => 'app-123',
                        'type' => 'USER',
                        'is_valid' => true,
                        'expires_at' => now()->addDays(50)->timestamp,
                    ],
                ], 200);
            }

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'refreshed-user-token',
                    'token_type' => 'bearer',
                    'expires_in' => 5184000,
                ], 200);
            }

            if (str_contains($url, '/page-1')) {
                return Http::response([
                    'access_token' => 'refreshed-page-token',
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

        $this->artisan('facebook:refresh-page-token')
            ->assertSuccessful();

        $this->assertSame('refreshed-page-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('refreshed-user-token', Setting::getValue(FacebookPageTokenService::USER_SETTING_KEY));
    }

    #[Test]
    public function refresh_command_fails_without_app_credentials(): void
    {
        config([
            'facebook.app_id' => '',
            'facebook.messenger.app_secret' => '',
            'facebook.messenger.page_access_token' => 'page-token',
        ]);

        $this->artisan('facebook:refresh-page-token')
            ->assertFailed();
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
