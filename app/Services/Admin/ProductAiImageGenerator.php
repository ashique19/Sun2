<?php

namespace App\Services\Admin;

use App\Models\AiImagePrompt;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProductAiImageGenerator
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ProductImageService $images,
    ) {}

    /**
     * Run a prompt sequence on a product photo and save each step as an admin-only image.
     *
     * @param  list<string>  $steps
     * @return array{ok: true, steps: int, product_image_ids: list<int>}|array{ok: false, error: string}
     */
    public function generateSequence(Product $product, array $steps, ?int $sourceImageId = null): array
    {
        $normalizedSteps = $this->normalizeSteps($steps);

        if ($normalizedSteps === []) {
            return ['ok' => false, 'error' => 'Add at least one instruction step (min 3 characters).'];
        }

        if (! $this->gemini->isConfigured()) {
            return ['ok' => false, 'error' => 'Gemini API key is not configured (GEMINI_API_KEY).'];
        }

        try {
            [$currentBase64, $currentMime] = $this->resolveSourceImage($product, $sourceImageId);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $stepCount = count($normalizedSteps);
        $createdIds = [];
        $systemPrompt = $this->systemPrompt();

        try {
            foreach ($normalizedSteps as $index => $step) {
                $stepNumber = $index + 1;
                $instruction = $this->stepInstruction($step, $stepNumber, $stepCount);

                $result = $this->gemini->generateImage([
                    ['text' => $instruction],
                    [
                        'inline_data' => [
                            'mime_type' => $currentMime === 'image/jpg' ? 'image/jpeg' : $currentMime,
                            'data' => $currentBase64,
                        ],
                    ],
                ], $systemPrompt);

                $binary = base64_decode((string) $result['base64'], true);

                if ($binary === false || $binary === '') {
                    throw new RuntimeException("Gemini returned an empty image on step {$stepNumber}.");
                }

                $normalized = $this->images->normalizeToGalleryJpeg($binary);
                $image = $this->storeAdminOnlyBinary(
                    $product,
                    $normalized,
                    $stepCount > 1 ? "AI step {$stepNumber}" : 'AI',
                );
                $createdIds[] = $image->id;

                AiImagePrompt::remember($step, Auth::id());

                $currentBase64 = base64_encode($normalized);
                $currentMime = 'image/jpeg';
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'steps' => $stepCount,
            'product_image_ids' => $createdIds,
        ];
    }

    /**
     * Prefer an explicit product image, else primary/first non-admin gallery image, else any readable image.
     *
     * @return array{0: string, 1: string} [base64, mime]
     */
    public function resolveSourceImage(Product $product, ?int $sourceImageId = null): array
    {
        $product->loadMissing(['images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')]);

        $image = null;

        if ($sourceImageId) {
            $image = $product->images->firstWhere('id', $sourceImageId);

            if (! $image) {
                throw new InvalidArgumentException('The selected product image was not found on this product.');
            }
        } else {
            $image = $product->images->first(fn (ProductImage $row) => ! $row->is_admin_only)
                ?? $product->images->first();
        }

        if (! $image) {
            throw new InvalidArgumentException('This product has no photos to use as an AI source.');
        }

        $absolute = public_path(ltrim(str_replace('\\', '/', (string) $image->path), '/'));

        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new InvalidArgumentException('The product image file is not readable.');
        }

        $binary = file_get_contents($absolute);

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('The product image is empty.');
        }

        if (strlen($binary) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('The product image must be 8 MB or smaller.');
        }

        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? (string) ($info['mime'] ?? 'image/jpeg') : 'image/jpeg';

        return [base64_encode($binary), $mime];
    }

    /**
     * @param  list<string>|array<int, mixed>  $steps
     * @return list<string>
     */
    public function normalizeSteps(array $steps): array
    {
        $normalized = [];

        foreach ($steps as $step) {
            if (! is_string($step) && ! is_numeric($step)) {
                continue;
            }

            $text = trim((string) $step);

            if (strlen($text) < 3) {
                continue;
            }

            if (strlen($text) > 4000) {
                $text = substr($text, 0, 4000);
            }

            $normalized[] = $text;
        }

        return array_values($normalized);
    }

    private function storeAdminOnlyBinary(Product $product, string $jpegBinary, string $altSuffix): ProductImage
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'aiimg_');

        if ($tempPath === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        $pathWithExt = $tempPath.'.jpg';
        rename($tempPath, $pathWithExt);
        file_put_contents($pathWithExt, $jpegBinary);

        try {
            $upload = new UploadedFile(
                $pathWithExt,
                'ai-generated.jpg',
                'image/jpeg',
                null,
                true,
            );

            return $this->images->store(
                $product,
                $upload,
                $product->name.' ('.$altSuffix.')',
                true,
            );
        } finally {
            if (is_file($pathWithExt)) {
                @unlink($pathWithExt);
            }
        }
    }

    private function systemPrompt(): string
    {
        return 'You edit product photos for a Bangladeshi jewelry e-commerce catalog. '
            .'You receive the current product photo and ONE editing instruction. '
            .'Apply only that instruction to the provided photo. '
            .'Preserve the same product identity, jewellery piece, shape, and materials unless the instruction explicitly changes them. '
            .'Do not invent a different product, replace the jewellery with a new design, or ignore the reference photo. '
            .'Return one edited image.';
    }

    private function stepInstruction(string $step, int $stepNumber, int $stepCount): string
    {
        if ($stepCount <= 1) {
            return $step;
        }

        return "Step {$stepNumber} of {$stepCount} — continue editing THIS same product photo (do not start a new product): {$step}";
    }
}
