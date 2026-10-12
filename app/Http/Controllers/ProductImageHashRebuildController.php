<?php

namespace App\Http\Controllers;

use App\Services\Admin\ProductImageHashRebuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProductImageHashRebuildController extends Controller
{
    public function __invoke(Request $request, ProductImageHashRebuildService $hashes): JsonResponse
    {
        $token = (string) config('products.image_hash_rebuild_token');

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            throw new AccessDeniedHttpException('Invalid product image hash rebuild token.');
        }

        $force = $request->boolean('force');

        // Work in batches within this request's PHP time limit; the next call resumes the run.
        $limit = (int) ini_get('max_execution_time');
        $seconds = $limit > 0 ? max(2.0, $limit * 0.6 - ProductImageHashRebuildService::tickBudgetSeconds()) : 50.0;

        $run = $hashes->runFor($hashes->activeRun() ?? $hashes->start('http', force: $force), $seconds);

        return response()->json([
            'ok' => $run->status === 'completed',
            'done' => ! $run->isActive(),
            'status' => $run->status,
            'message' => $run->message,
            'force' => (bool) $run->force,
            'hashed_ok' => $run->hashed_ok,
            'failed' => $run->failed,
            'progress_percent' => $run->progressPercent(),
            'error' => $run->error,
            'coverage' => $hashes->coverage(),
        ], $run->status === 'failed' ? 500 : 200);
    }
}
