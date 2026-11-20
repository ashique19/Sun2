<?php

namespace App\Livewire\Admin;

use App\Services\Channels\InboxAdvanceAutomationService;
use App\Services\Channels\InboxAutomationSettings;
use App\Services\Channels\InboxColorGuaranteeAutomationService;
use App\Services\Channels\InboxDeliveryChargeAutomationService;
use App\Services\Channels\InboxHiReplyAutomationService;
use App\Services\Channels\InboxLocationAutomationService;
use App\Services\Channels\InboxOrderProcessAutomationService;
use App\Services\Channels\InboxPriceNegotiateAutomationService;
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

    public bool $colorGuarantee = true;

    public bool $deliveryCharge = true;

    public bool $location = true;

    public bool $advance = true;

    public bool $priceNegotiate = true;

    public bool $orderProcess = true;

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

    public function toggleColorGuarantee(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::COLOR_GUARANTEE, ! $this->colorGuarantee);
    }

    public function toggleDeliveryCharge(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::DELIVERY_CHARGE, ! $this->deliveryCharge);
    }

    public function toggleLocation(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::LOCATION, ! $this->location);
    }

    public function toggleAdvance(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::ADVANCE, ! $this->advance);
    }

    public function togglePriceNegotiate(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::PRICE_NEGOTIATE, ! $this->priceNegotiate);
    }

    public function toggleOrderProcess(InboxAutomationSettings $settings): void
    {
        $this->persist($settings, InboxAutomationSettings::ORDER_PROCESS, ! $this->orderProcess);
    }

    public function render()
    {
        return view('livewire.admin.admin-inbox-automations', [
            'photoRequestBody' => InboxPriceQueryAutomationService::PHOTO_REQUEST_BODY,
            'lowMatchWaitBody' => InboxPriceQueryAutomationService::LOW_MATCH_WAIT_BODY,
            'hiReplyBody' => InboxHiReplyAutomationService::BODY,
            'colorGuaranteeBody' => app(InboxColorGuaranteeAutomationService::class)->replyBody()
                ?? InboxColorGuaranteeAutomationService::REPLY_LABEL,
            'colorGuaranteeLabel' => InboxColorGuaranteeAutomationService::REPLY_LABEL,
            'colorGuaranteeCategory' => InboxColorGuaranteeAutomationService::CATEGORY_NAME,
            'deliveryChargeBody' => InboxDeliveryChargeAutomationService::BODY,
            'deliveryChargeNamedExample' => InboxDeliveryChargeAutomationService::personalizedBody('সাভার'),
            'deliveryChargeDhakaNamedExample' => InboxDeliveryChargeAutomationService::personalizedDhakaBody('মিরপুর'),
            'deliveryChargeKobeAskBody' => InboxDeliveryChargeAutomationService::BODY_KOBE_ASK,
            'deliveryChargeKobeDhakaExample' => InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_DHAKA),
            'deliveryChargeKobeOutsideExample' => InboxDeliveryChargeAutomationService::weekdayDeliveryBody(InboxDeliveryChargeAutomationService::ZONE_OUTSIDE),
            'deliveryChargeLabel' => InboxDeliveryChargeAutomationService::REPLY_LABEL,
            'locationBody' => InboxLocationAutomationService::BODY,
            'locationLabel' => InboxLocationAutomationService::REPLY_LABEL,
            'advanceLabel' => InboxAdvanceAutomationService::REPLY_LABEL,
            'advanceAskBody' => InboxAdvanceAutomationService::BODY_ASK_LOCATION,
            'advanceListedExample' => InboxAdvanceAutomationService::listedBody('কুমিল্লা'),
            'advanceUnlistedBody' => InboxAdvanceAutomationService::unlistedBody(),
            'advanceCodExample' => InboxAdvanceAutomationService::codRefusalBody('কুমিল্লা'),
            'priceNegotiateBodies' => [
                InboxPriceNegotiateAutomationService::BODY_1,
                InboxPriceNegotiateAutomationService::BODY_2,
                InboxPriceNegotiateAutomationService::BODY_3,
            ],
            'orderProcessLabel' => InboxOrderProcessAutomationService::REPLY_LABEL,
            'orderProcessBody' => app(InboxOrderProcessAutomationService::class)->replyBody(),
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
            InboxAutomationSettings::COLOR_GUARANTEE => $key === InboxAutomationSettings::COLOR_GUARANTEE ? $value : $this->colorGuarantee,
            InboxAutomationSettings::DELIVERY_CHARGE => $key === InboxAutomationSettings::DELIVERY_CHARGE ? $value : $this->deliveryCharge,
            InboxAutomationSettings::LOCATION => $key === InboxAutomationSettings::LOCATION ? $value : $this->location,
            InboxAutomationSettings::ADVANCE => $key === InboxAutomationSettings::ADVANCE ? $value : $this->advance,
            InboxAutomationSettings::PRICE_NEGOTIATE => $key === InboxAutomationSettings::PRICE_NEGOTIATE ? $value : $this->priceNegotiate,
            InboxAutomationSettings::ORDER_PROCESS => $key === InboxAutomationSettings::ORDER_PROCESS ? $value : $this->orderProcess,
        ]);
        $this->photoRequest = $saved[InboxAutomationSettings::PHOTO_REQUEST];
        $this->pricedImage = $saved[InboxAutomationSettings::PRICED_IMAGE];
        $this->lowMatchWait = $saved[InboxAutomationSettings::LOW_MATCH_WAIT];
        $this->hiReply = $saved[InboxAutomationSettings::HI_REPLY];
        $this->colorGuarantee = $saved[InboxAutomationSettings::COLOR_GUARANTEE];
        $this->deliveryCharge = $saved[InboxAutomationSettings::DELIVERY_CHARGE];
        $this->location = $saved[InboxAutomationSettings::LOCATION];
        $this->advance = $saved[InboxAutomationSettings::ADVANCE];
        $this->priceNegotiate = $saved[InboxAutomationSettings::PRICE_NEGOTIATE];
        $this->orderProcess = $saved[InboxAutomationSettings::ORDER_PROCESS];
        $this->statusMessage = 'Automation saved.';
    }

    private function hydrateFrom(InboxAutomationSettings $settings): void
    {
        $flags = $settings->all();
        $this->photoRequest = $flags[InboxAutomationSettings::PHOTO_REQUEST];
        $this->pricedImage = $flags[InboxAutomationSettings::PRICED_IMAGE];
        $this->lowMatchWait = $flags[InboxAutomationSettings::LOW_MATCH_WAIT];
        $this->hiReply = $flags[InboxAutomationSettings::HI_REPLY];
        $this->colorGuarantee = $flags[InboxAutomationSettings::COLOR_GUARANTEE];
        $this->deliveryCharge = $flags[InboxAutomationSettings::DELIVERY_CHARGE];
        $this->location = $flags[InboxAutomationSettings::LOCATION];
        $this->advance = $flags[InboxAutomationSettings::ADVANCE];
        $this->priceNegotiate = $flags[InboxAutomationSettings::PRICE_NEGOTIATE];
        $this->orderProcess = $flags[InboxAutomationSettings::ORDER_PROCESS];
    }
}
