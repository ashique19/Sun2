<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInboxAutomations;
use App\Models\User;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxColorGuaranteeAutomationService;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxHiReplyAutomationService;
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

    private function switchChecked(string $html, string $label): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $switch = $xpath->query('//*[@role="switch"][@aria-label="'.$label.'"]')->item(0);
        $this->assertNotNull($switch, 'Missing switch: '.$label);

        return $switch->getAttribute('aria-checked');
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
            ->assertSee('Reply Hi apu')
            ->assertSee('Match '.InboxColorGuaranteeAutomationService::REPLY_LABEL)
            ->assertSee('Match '.InboxDeliveryChargeAutomationService::REPLY_LABEL)
            ->assertSee(InboxDeliveryChargeAutomationService::BODY_DHAKA)
            ->assertSee(InboxDeliveryChargeAutomationService::BODY_OUTSIDE)
            ->assertSee(InboxDeliveryChargeAutomationService::personalizedBody('সাভার'))
            ->assertSee(InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY)
            ->assertSee(InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY)
            ->assertSee(InboxHiReplyAutomationService::BODY)
            ->assertSee(InboxColorGuaranteeAutomationService::REPLY_LABEL)
            ->assertSee(InboxColorGuaranteeAutomationService::CATEGORY_NAME)
            ->assertSeeHtml('data-inbox-automations')
            ->assertSeeHtml('data-inbox-automation="photo_request"')
            ->assertSeeHtml('data-inbox-automation="priced_image"')
            ->assertSeeHtml('data-inbox-automation="low_match_wait"')
            ->assertSeeHtml('data-inbox-automation="hi_reply"')
            ->assertSeeHtml('data-inbox-automation="color_guarantee"')
            ->assertSeeHtml('data-inbox-automation="delivery_charge"')
            ->assertSeeHtml('class="admin-switch"')
            ->assertSeeHtml('class="admin-switch__knob"')
            ->assertSeeHtml('dir="ltr"');
    }

    #[Test]
    public function on_switch_is_aria_checked_so_the_knob_sits_on_the_right(): void
    {
        $this->actingAs($this->adminUser());

        $html = Livewire::test(AdminInboxAutomations::class)->html();

        $this->assertSame('true', $this->switchChecked($html, 'Ask for a product photo'));
        $this->assertSame('true', $this->switchChecked($html, 'Reply Hi apu'));
        $this->assertSame('true', $this->switchChecked($html, 'Match color guarantee'));
        $this->assertSame('true', $this->switchChecked($html, 'Match delivery charge'));
        $this->assertSame('false', $this->switchChecked($html, 'Send priced image'));
        $this->assertSame('false', $this->switchChecked($html, 'Ask for a moment on a weak match'));
        $this->assertStringNotContainsString('ml-6', $html);

        $toggled = Livewire::test(AdminInboxAutomations::class)
            ->call('togglePhotoRequest')
            ->call('togglePricedImage')
            ->html();

        $this->assertSame('false', $this->switchChecked($toggled, 'Ask for a product photo'));
        $this->assertSame('true', $this->switchChecked($toggled, 'Send priced image'));
    }

    #[Test]
    public function toggling_each_automation_persists(): void
    {
        $this->actingAs($this->adminUser());
        $settings = app(InboxAutomationSettings::class);

        $this->assertTrue($settings->enabled(InboxAutomationSettings::PHOTO_REQUEST));
        $this->assertTrue($settings->enabled(InboxAutomationSettings::HI_REPLY));
        $this->assertTrue($settings->enabled(InboxAutomationSettings::COLOR_GUARANTEE));
        $this->assertTrue($settings->enabled(InboxAutomationSettings::DELIVERY_CHARGE));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::PRICED_IMAGE));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::LOW_MATCH_WAIT));

        Livewire::test(AdminInboxAutomations::class)
            ->assertSet('photoRequest', true)
            ->assertSet('hiReply', true)
            ->assertSet('colorGuarantee', true)
            ->assertSet('deliveryCharge', true)
            ->assertSet('pricedImage', false)
            ->assertSet('lowMatchWait', false)
            ->call('togglePricedImage')
            ->assertSet('pricedImage', true)
            ->assertSet('statusMessage', 'Automation saved.')
            ->call('toggleLowMatchWait')
            ->assertSet('lowMatchWait', true)
            ->call('togglePhotoRequest')
            ->assertSet('photoRequest', false)
            ->call('toggleHiReply')
            ->assertSet('hiReply', false)
            ->call('toggleColorGuarantee')
            ->assertSet('colorGuarantee', false)
            ->call('toggleDeliveryCharge')
            ->assertSet('deliveryCharge', false);

        $saved = $settings->all();
        $this->assertFalse($saved[InboxAutomationSettings::PHOTO_REQUEST]);
        $this->assertFalse($saved[InboxAutomationSettings::HI_REPLY]);
        $this->assertFalse($saved[InboxAutomationSettings::COLOR_GUARANTEE]);
        $this->assertFalse($saved[InboxAutomationSettings::DELIVERY_CHARGE]);
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
