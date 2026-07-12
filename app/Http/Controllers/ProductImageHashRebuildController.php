<?php

namespace App\Http\Controllers;

use App\Services\Admin\ProductImageHashRebuildService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProductImageHashRebuildController extends Controller
{
    public function __invoke(Request $request, ProductImageHashRebuildService $hashes): Response
    {
        $token = (string) config('products.image_hash_rebuild_token');

        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            throw new AccessDeniedHttpException('Invalid product image hash rebuild token.');
        }

        $force = $request->boolean('force');

        if ($active = $hashes->activeRun()) {
            $run = $hashes->runToCompletion($active);
        } else {
            $run = $hashes->runToCompletion(
                $hashes->start('http', force: $force),
            );
        }

        return response()->json([
            'ok' => $run->status === 'completed',
            'status' => $run->status,
            'message' => $run->message,
            'force' => (bool) $run->force,
            'hashed_ok' => $run->hashed_ok,
            'failed' => $run->failed,
            'progress_percent' => $run->progressPercent(),
            'error' => $run->error,
            'coverage' => $hashes->coverage(),
        ], $run->status === 'completed' ? 200 : 500);
    }
}
