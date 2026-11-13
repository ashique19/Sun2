<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\AdminAccess;
use App\Support\OrderPrintSlip;
use App\Support\OrderPrintVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class AdminOrderPrintController extends Controller
{
    public function __invoke(Request $request, Order $order): Response
    {
        AdminAccess::ensureCanViewOrder($order);

        $raw = $request->query('variant', OrderPrintVariant::CourierMinimal->value);
        $variant = is_string($raw) ? OrderPrintVariant::tryFrom($raw) : null;

        abort_if($variant === null, 404);

        $order->loadMissing(['items.product.images', 'adjustments']);

        $data = [
            'order' => $order,
            'variant' => $variant,
            'parcelId' => $order->printParcelId(),
            'shippingAddress' => OrderPrintSlip::shippingAddress($order),
            'helpline' => OrderPrintSlip::helpline(),
            'showPhotos' => $variant->includesProductPhotos(),
            'totals' => $order->moneyTotals(),
            'otgDeepLink' => null,
        ];

        if ($variant->usesOtg()) {
            $slipsUrl = URL::temporarySignedRoute(
                'print-slips',
                now()->addMinutes(30),
                ['ids' => (string) $order->id],
            );

            $data['otgDeepLink'] = 'sundoritoma://print?slips_url='.rawurlencode($slipsUrl);
        }

        return response()
            ->view($variant->view(), $data)
            ->header('Cache-Control', 'no-store');
    }
}
