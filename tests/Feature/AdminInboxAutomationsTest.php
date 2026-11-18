<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInboxAutomations;
use App\Models\User;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxPriceQueryAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxAutomationsTest extends TestCase
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
    public function automations_page_shows_the_three_toggles(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.inbox.automations'))
            ->assertOk()
            ->assertSee('Automations')
            ->assertSee('past hour')
            ->assertSee('Back to Inbox')
            ->assertSee('Ask for a product photo')
            ->assertSee('Send priced image')
            ->assertSee('Ask for a moment on a weak match')
            ->assertSee(InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY)
            ->assertSee(InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY)
            ->assertSeeHtml('data-inbox-automations')
            ->assertSeeHtml('data-inbox-automation="photo_request"')
            ->assertSeeHtml('data-inbox-automation="priced_image"')
            ->assertSeeHtml('data-inbox-automation="low_match_wait"');
    }

    #[Test]
    public function toggling_each_automation_persists(): void
    {
        $this->actingAs($this->adminUser());
        $settings = app(InboxAutomationSettings::class);

        $this->assertTrue($settings->enabled(InboxAutomationSettings::PHOTO_REQUEST));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::PRICED_IMAGE));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::LOW_MATCH_WAIT));

        Livewire::test(AdminInboxAutomations::class)
            ->assertSet('photoRequest', true)
            ->assertSet('pricedImage', false)
            ->assertSet('lowMatchWait', false)
            ->call('togglePricedImage')
            ->assertSet('pricedImage', true)
            ->assertSet('statusMessage', 'Automation saved.')
            ->call('toggleLowMatchWait')
            ->assertSet('lowMatchWait', true)
            ->call('togglePhotoRequest')
            ->assertSet('photoRequest', false);

        $saved = $settings->all();
        $this->assertFalse($saved[InboxAutomationSettings::PHOTO_REQUEST]);
        $this->assertTrue($saved[InboxAutomationSettings::PRICED_IMAGE]);
        $this->assertTrue($saved[InboxAutomationSettings::LOW_MATCH_WAIT]);
    }

    #[Test]
    public function sidebar_lists_automations_under_inbox(): void
    {
        $this->actingAs($this->adminUser());

        $this->get(route('admin.inbox'))
            ->assertOk()
            ->assertSee('Automations')
            ->assertSee(route('admin.inbox.automations'), false);
    }
}
