<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CustomerLookupService;
use App\Support\AdminAccess;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderSteadfastStatsController extends Controller
{
    /**
     * Steadfast delivery history for one phone.
     *
     * Runs outside Livewire so opening Inbox → Edit order cannot freeze the form
     * when Packzy is slow or down.
     */
    public function __invoke(Request $request, CustomerLookupService $lookup): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $phone = PhoneNumber::extractFirstBangladeshMobile($validated['phone'])
            ?? PhoneNumber::display($validated['phone']);

        if (! PhoneNumber::isValidDisplayMobile($phone)) {
            return response()->json([
                'phone' => $phone,
                'stats' => null,
                'error' => 'A valid phone number is required.',
            ]);
        }

        [$stats, $error] = $lookup->fraudStats($phone);

        return response()->json([
            'phone' => PhoneNumber::display($phone),
            'stats' => $stats,
            'error' => $error,
        ]);
    }
}
