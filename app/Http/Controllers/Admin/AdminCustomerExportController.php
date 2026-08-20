<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\CustomerExportService;
use App\Support\AdminAccess;
use App\Support\SimpleXlsxExporter;
use Illuminate\Database\QueryException;
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
        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'B,D', 'location' => 'AdminCustomerExportController.php:entry', 'message' => 'export download hit', 'data' => ['token' => $token, 'authId' => $request->user()?->id, 'authGuardCheck' => auth()->check(), 'sessionId' => $request->session()->getId(), 'cacheDriver' => config('cache.default'), 'dbDriver' => config('database.default'), 'memoryLimit' => ini_get('memory_limit'), 'zipLoaded' => extension_loaded('zip')], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        AdminAccess::ensureStaffAdmin();

        $cacheKey = CustomerExportService::cacheKey($token);
        $filters = Cache::pull($cacheKey);

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'B,D', 'location' => 'AdminCustomerExportController.php:afterPull', 'message' => 'cache pull result', 'data' => ['cacheKey' => $cacheKey, 'cacheHit' => is_array($filters), 'filterKeys' => is_array($filters) ? array_keys($filters) : null, 'filterUserId' => is_array($filters) ? ($filters['user_id'] ?? null) : null, 'requestUserId' => $request->user()?->id, 'userIdMatch' => is_array($filters) && (int) ($filters['user_id'] ?? 0) === (int) ($request->user()?->id ?? 0)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        if (! is_array($filters) || (int) ($filters['user_id'] ?? 0) !== (int) $request->user()->id) {
            // #region agent log
            file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'B,D', 'location' => 'AdminCustomerExportController.php:abort404', 'message' => 'aborting 404 cache miss or user mismatch', 'data' => ['reason' => ! is_array($filters) ? 'cache_miss' : 'user_mismatch'], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion
            abort(404);
        }

        try {
            $file = $exports->writeXlsx($filters, $xlsx);
        } catch (Throwable $e) {
            // #region agent log
            file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'A,C,E,F', 'location' => 'AdminCustomerExportController.php:catch', 'message' => 'writeXlsx threw', 'data' => ['exception' => $e::class, 'error' => $e->getMessage(), 'code' => $e->getCode(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'sql' => method_exists($e, 'getSql') ? $e->getSql() : null, 'sqlState' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null, 'driverCode' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null, 'memoryPeak' => memory_get_peak_usage(true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion
            report($e);
            abort(500, 'Unable to export customers.');
        }

        // #region agent log
        file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'C', 'location' => 'AdminCustomerExportController.php:success', 'message' => 'xlsx ready for download', 'data' => ['path' => $file['path'], 'filename' => $file['filename'], 'exists' => is_file($file['path']), 'size' => is_file($file['path']) ? filesize($file['path']) : null, 'memoryPeak' => memory_get_peak_usage(true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        return response()->download(
            $file['path'],
            $file['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }
}
