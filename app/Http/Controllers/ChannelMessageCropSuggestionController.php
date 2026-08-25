<?php

namespace App\Http\Controllers;

use App\Models\ChannelMessage;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ScreenshotSubjectDetector;
use App\Services\Channels\ChannelMessageImageMatchService;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;

class ChannelMessageCropSuggestionController extends Controller
{
    /**
     * Return content-aware crop bounds for inbox screenshot tagging.
     */
    public function __invoke(
        ChannelMessage $message,
        ChannelMessageImageMatchService $media,
        ProductImageHashService $hasher,
        ScreenshotSubjectDetector $subjects,
    ): JsonResponse {
        AdminAccess::ensureStaffAdmin();

        if (! $message->isImageAttachment()) {
            abort(404);
        }

        $downloaded = $media->downloadInboundImageBytes($message);

        if ($downloaded === null) {
            return response()->json(['suggestion' => null]);
        }

        $heuristic = $hasher->suggestScreenshotCropFractions($downloaded['bytes']);
        $subject = null;

        if ($subjects->shouldRefine($heuristic)) {
            $subject = $subjects->detectCropFractions(
                $downloaded['bytes'],
                $downloaded['mime'] ?? $message->media_mime,
            );
        }

        return response()->json([
            'suggestion' => $hasher->preferCropSuggestion($heuristic, $subject),
        ]);
    }
}
