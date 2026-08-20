<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\CustomerExportService;
use App\Support\AdminAccess;
use App\Support\SimpleXlsxExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminCustomerExportController
{
    public function __invoke(
        Request $request,
        string $token,
        CustomerExportService $exports,
        SimpleXlsxExporter $xlsx,
    ): StreamedResponse {
        AdminAccess::ensureStaffAdmin();

        $filters = $exports->pull($token);

        if (! is_array($filters) || (int) ($filters['user_id'] ?? 0) !== (int) $request->user()->id) {
            abort(404);
        }

        try {
            $file = $exports->buildXlsx($filters, $xlsx);
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Unable to export customers.');
        }

        return response()->streamDownload(function () use ($file): void {
            echo $file['binary'];
        }, $file['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
