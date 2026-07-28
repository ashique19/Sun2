<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminFacebookTokenGate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Paste new FACEBOOK_PAGE_ACCESS_TOKEN');
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
            ->assertSee('Update token')
            ->assertSee('Facebook Page token');

        $this->assertSame('new-valid-token', Setting::getValue(FacebookPageTokenService::SETTING_KEY));
        $this->assertSame('new-valid-token', app(FacebookPageTokenService::class)->token());
    }

    #[Test]
    public function valid_token_still_offers_update_form_on_demand(): void
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
            ->assertSee('Facebook Page token')
            ->assertSee('Update token')
            ->assertDontSee('Paste new FACEBOOK_PAGE_ACCESS_TOKEN')
            ->call('toggleUpdateForm')
            ->assertSee('Paste new FACEBOOK_PAGE_ACCESS_TOKEN')
            ->assertSee('Save token');
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
