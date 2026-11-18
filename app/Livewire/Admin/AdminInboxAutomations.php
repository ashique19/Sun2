<?php

namespace App\Livewire\Admin;

use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxHiReplyAutomationService;
use App\Services\Channels\InboxPriceQueryAutomationService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Inbox Automations')]
#[Layout('components.layouts.admin')]
class AdminInboxAutomations extends Component
{
    public bool $photoRequest = true;

    public bool $pricedImage = false;

    public bool $lowMatchWait = false;

    public bool $hiReply = true;

    public ?string $statusMessage = null;

    public function mount(InboxAutomationSettings $settings): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->hydrateFrom($settings);
    }

    public function togglePhotoRequest(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::PHOTO_REQUEST, ! $this->photoRequest);
    }

    public function togglePricedImage(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::PRICED_IMAGE, ! $this->pricedImage);
    }

    public function toggleLowMatchWait(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::LOW_MATCH_WAIT, ! $this->lowMatchWait);
    }

    public function toggleHiReply(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::HI_REPLY, ! $this->hiReply);
    }

    public function render()
    {
        return view('livewire.admin.admin-inbox-automations', [
            'photoRequestBody' => InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY,
            'lowMatchWaitBody' => InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY,
            'hiReplyBody' => InboxHiReplyAutomationService::BODY,
            'matchMinPercent' => InboxPriceQueryAutomationService::MATCH_MIN_PERCENT,
            'sessionMinutes' => InboxPriceQueryAutomationService::sessionMinutes(),
        ]);
    }

    private function persist(InboxAutomationSettings $settings, string $key, bool $value): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->statusMessage = null;
        $saved = $settings->save([
            InboxAutomationSettings::PHOTO_REQUEST => $key === InboxAutomationSettings::PHOTO_REQUEST ? $value : $this->photoRequest,
            InboxAutomationSettings::PRICED_IMAGE => $key === InboxAutomationSettings::PRICED_IMAGE ? $value : $this->pricedImage,
            InboxAutomationSettings::LOW_MATCH_WAIT => $key === InboxAutomationSettings::LOW_MATCH_WAIT ? $value : $this->lowMatchWait,
            InboxAutomationSettings::HI_REPLY => $key === InboxAutomationSettings::HI_REPLY ? $value : $this->hiReply,
        ]);
        $this->photoRequest = $saved[InboxAutomationSettings::PHOTO_REQUEST];
        $this->pricedImage = $saved[InboxAutomationSettings::PRICED_IMAGE];
        $this->lowMatchWait = $saved[InboxAutomationSettings::LOW_MATCH_WAIT];
        $this->hiReply = $saved[InboxAutomationSettings::HI_REPLY];
        $this->statusMessage = 'Automation saved.';
    }

    private function hydrateFrom(InboxAutomationSettings $settings): void
    {
        $flags = $settings->all();
        $this->photoRequest = $flags[InboxAutomationSettings::PHOTO_REQUEST];
        $this->pricedImage = $flags[InboxAutomationSettings::PRICED_IMAGE];
        $this->lowMatchWait = $flags[InboxAutomationSettings::LOW_MATCH_WAIT];
        $this->hiReply = $flags[InboxAutomationSettings::HI_REPLY];
    }
}
