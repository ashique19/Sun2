<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;
use Throwable;

class ScreenshotSubjectDetector
{
    /**
     * Max edge for the payload sent to Gemini (keeps latency/cost down).
     */
    public const DETECT_MAX_EDGE = 1024;

    public function __construct(
        private GeminiClient $gemini,
    ) {}

    public function isEnabled(): bool
    {
        if (! filter_var(config('channels.ai_draft.image_subject_detect', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->gemini->isConfigured();
    }

    /**
     * Whether vision should refine a heuristic crop (or run when heuristics miss).
     *
     * @param  array{left: float, top: float, width: float, height: float, strategy: string}|null  $heuristic
     */
    public function shouldRefine(?array $heuristic): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($heuristic === null) {
            return true;
        }

        $bottom = (float) $heuristic['top'] + (float) $heuristic['height'];

        // Heuristic crops that still reach deep into the screenshot usually include FB UI.
        return $bottom > 0.70 || (float) $heuristic['height'] > 0.58;
    }

    /**
     * Ask Gemini for the main product photograph box as 0–1 fractions.
     *
     * @return array{left: float, top: float, width: float, height: float, strategy: string}|null
     */
    public function detectCropFractions(string $binary, ?string $mime = null): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $payload = $this->encodeForVision($binary, $mime);
        if ($payload === null) {
            return null;
        }

        $system = <<<'PROMPT'
You locate the main product photograph inside a mobile screenshot.
The screenshot often includes Facebook / Messenger / Android UI noise:
status bars, "X / n of m" headers, profile rows, "Send message" buttons,
like/comment bars, chat bubbles, ad thumbnails, and navigation chrome.

Return JSON only:
{
  "left": 0.0-1.0,
  "top": 0.0-1.0,
  "width": 0.0-1.0,
  "height": 0.0-1.0,
  "confidence": 0.0-1.0
}

Rules:
- Box must cover the product photo / jewelry subject only.
- Exclude UI chrome, captions, buttons, and thumbnail strips under ads.
- Prefer the largest central product image when several photos appear.
- If no product photo is visible, return {"left":0,"top":0,"width":0,"height":0,"confidence":0}.
PROMPT;

        try {
            $json = $this->gemini->generateJsonFromParts($system, [
                ['text' => 'Locate the product photograph bounding box in this customer screenshot.'],
                [
                    'inline_data' => [
                        'mime_type' => $payload['mime'],
                        'data' => $payload['base64'],
                    ],
                ],
            ], [
                'temperature' => 0.0,
            ]);
        } catch (Throwable $e) {
            Log::debug('Screenshot subject vision failed.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->normalizeFractions($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{left: float, top: float, width: float, height: float, strategy: string}|null
     */
    private function normalizeFractions(array $json): ?array
    {
        $left = $this->clampFraction($json['left'] ?? null);
        $top = $this->clampFraction($json['top'] ?? null);
        $width = $this->clampFraction($json['width'] ?? null);
        $height = $this->clampFraction($json['height'] ?? null);
        $confidence = is_numeric($json['confidence'] ?? null) ? (float) $json['confidence'] : 0.0;

        if ($left === null || $top === null || $width === null || $height === null) {
            return null;
        }

        if ($confidence > 0 && $confidence < 0.35) {
            return null;
        }

        if ($width < 0.12 || $height < 0.10) {
            return null;
        }

        if (($left + $width) > 1.02 || ($top + $height) > 1.02) {
            $width = min($width, 1.0 - $left);
            $height = min($height, 1.0 - $top);
        }

        if ($width < 0.12 || $height < 0.10) {
            return null;
        }

        return [
            'left' => round($left, 4),
            'top' => round($top, 4),
            'width' => round($width, 4),
            'height' => round($height, 4),
            'strategy' => 'subject_vision',
        ];
    }

    private function clampFraction(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number < 0.0 || $number > 1.0) {
            return null;
        }

        return $number;
    }

    /**
     * @return array{base64: string, mime: string}|null
     */
    private function encodeForVision(string $binary, ?string $mime): ?array
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $maxEdge = max($width, $height);

            if ($maxEdge > self::DETECT_MAX_EDGE) {
                $scale = self::DETECT_MAX_EDGE / $maxEdge;
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));
                $scaled = imagecreatetruecolor($newWidth, $newHeight);
                if ($scaled === false) {
                    return null;
                }
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $scaled;
            }

            ob_start();
            imagejpeg($image, null, 82);
            $encoded = (string) ob_get_clean();

            if ($encoded === '') {
                return null;
            }

            return [
                'base64' => base64_encode($encoded),
                'mime' => 'image/jpeg',
            ];
        } finally {
            imagedestroy($image);
        }
    }
}
