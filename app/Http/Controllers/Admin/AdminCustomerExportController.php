<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\CustomerExportService;
use App\Support\AdminAccess;
use App\Support\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminCustomerExportController
{
    public function __invoke(
        Request $request,
        string $token,
        CustomerExportService $exports,
        SimpleXlsxExporter $xlsx,
    ): BinaryFileResponse {
        AdminAccess::ensureStaffAdmin();

        $filters = Cache::pull(CustomerExportService::cacheKey($token));

        if (! is_array($filters) || (int) ($filters['user_id'] ?? 0) !== (int) $request->user()->id) {
            abort(404);
        }

        try {
            $file = $exports->writeXlsx($filters, $xlsx);
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Unable to export customers.');
        }

        return response()->download(
            $file['path'],
            $file['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }
}
