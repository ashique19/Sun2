<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInboxAutomations;
use App\Models\User;
use App\Services\Channels\InboxAdvanceAutomationService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxColorGuaranteeAutomationService;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxHiReplyAutomationService;
use App\Services\Channels\InboxLocationAutomationService;
use App\Services\Channels\InboxPriceNegotiateAutomationService;
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
            ->assertSee('Match '.InboxLocationAutomationService::REPLY_LABEL)
            ->assertSee('Match '.InboxAdvanceAutomationService::REPLY_LABEL)
            ->assertSee('Reply when they negotiate the price')
            ->assertSee('Price Fixed saved reply is matched')
            ->assertSee(InboxDeliveryChargeAutomationService::BODY_DHAKA)
            ->assertSee(InboxDeliveryChargeAutomationService::BODY_OUTSIDE)
            ->assertSee('known Dhaka-city thana')
            ->assertSee(InboxDeliveryChargeAutomationService::personalizedDhakaBody('মিরপুর'))
            ->assertSee(InboxDeliveryChargeAutomationService::personalizedBody('সাভার'))
            ->assertSee(InboxDeliveryChargeAutomationService::BODY_KOBE_ASK)
            ->assertSee(InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_DHAKA))
            ->assertSee(InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_OUTSIDE))
            ->assertSee(InboxLocationAutomationService::BODY)
            ->assertSee(InboxAdvanceAutomationService::BODY_ASK_LOCATION)
            ->assertSee(InboxAdvanceAutomationService::listedBody('কুমিল্লা'))
            ->assertSee(InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY)
            ->assertSee(InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY)
            ->assertSee(InboxHiReplyAutomationService::BODY)
            ->assertSee(InboxPriceNegotiateAutomationService::BODY_2)
            ->assertSee(InboxPriceNegotiateAutomationService::BODY_3)
            ->assertSee(InboxColorGuaranteeAutomationService::REPLY_LABEL)
            ->assertSee(InboxColorGuaranteeAutomationService::CATEGORY_NAME)
            ->assertSeeHtml('data-inbox-automations')
            ->assertSeeHtml('data-inbox-automation="photo_request"')
            ->assertSeeHtml('data-inbox-automation="priced_image"')
            ->assertSeeHtml('data-inbox-automation="low_match_wait"')
            ->assertSeeHtml('data-inbox-automation="hi_reply"')
            ->assertSeeHtml('data-inbox-automation="color_guarantee"')
            ->assertSeeHtml('data-inbox-automation="delivery_charge"')
            ->assertSeeHtml('data-inbox-automation="location"')
            ->assertSeeHtml('data-inbox-automation="advance"')
            ->assertSeeHtml('data-inbox-automation="price_negotiate"')
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
        $this->assertSame('true', $this->switchChecked($html, 'Match location'));
        $this->assertSame('true', $this->switchChecked($html, 'Match advance'));
        $this->assertSame('true', $this->switchChecked($html, 'Reply when they negotiate the price'));
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
        $this->assertTrue($settings->enabled(InboxAutomationSettings::LOCATION));
        $this->assertTrue($settings->enabled(InboxAutomationSettings::ADVANCE));
        $this->assertTrue($settings->enabled(InboxAutomationSettings::PRICE_NEGOTIATE));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::PRICED_IMAGE));
        $this->assertFalse($settings->enabled(InboxAutomationSettings::LOW_MATCH_WAIT));

        Livewire::test(AdminInboxAutomations::class)
            ->assertSet('photoRequest', true)
            ->assertSet('hiReply', true)
            ->assertSet('colorGuarantee', true)
            ->assertSet('deliveryCharge', true)
            ->assertSet('location', true)
            ->assertSet('advance', true)
            ->assertSet('priceNegotiate', true)
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
            ->assertSet('deliveryCharge', false)
            ->call('toggleLocation')
            ->assertSet('location', false)
            ->call('toggleAdvance')
            ->assertSet('advance', false)
            ->call('togglePriceNegotiate')
            ->assertSet('priceNegotiate', false);

        $saved = $settings->all();
        $this->assertFalse($saved[InboxAutomationSettings::PHOTO_REQUEST]);
        $this->assertFalse($saved[InboxAutomationSettings::HI_REPLY]);
        $this->assertFalse($saved[InboxAutomationSettings::COLOR_GUARANTEE]);
        $this->assertFalse($saved[InboxAutomationSettings::DELIVERY_CHARGE]);
        $this->assertFalse($saved[InboxAutomationSettings::LOCATION]);
        $this->assertFalse($saved[InboxAutomationSettings::ADVANCE]);
        $this->assertFalse($saved[InboxAutomationSettings::PRICE_NEGOTIATE]);
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
