<?php

namespace App\Services\Admin;

use App\Models\AiImagePrompt;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
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

        $prepared = $this->prepareWorkingInput($product, $sourceImageId);

        if (($prepared['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string) ($prepared['error'] ?? 'Could not prepare source image.')];
        }

        $createdIds = [];

        try {
            foreach ($normalizedSteps as $index => $step) {
                $result = $this->generateStep($product, $normalizedSteps, $index);

                if (($result['ok'] ?? false) !== true) {
                    return ['ok' => false, 'error' => (string) ($result['error'] ?? 'AI generation failed.')];
                }

                $createdIds[] = (int) $result['product_image_id'];
            }
        } finally {
            $this->forgetWorkingInput($product);
        }

        return [
            'ok' => true,
            'steps' => count($normalizedSteps),
            'product_image_ids' => $createdIds,
        ];
    }

    /**
     * Resolve the source photo and store it as the working input for step-by-step generation.
     *
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function prepareWorkingInput(Product $product, ?int $sourceImageId = null): array
    {
        if (! $this->gemini->isConfigured()) {
            return ['ok' => false, 'error' => 'Gemini API key is not configured (GEMINI_API_KEY).'];
        }

        try {
            [$base64, $mime] = $this->resolveSourceImage($product, $sourceImageId);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $binary = base64_decode($base64, true);

        if ($binary === false || $binary === '') {
            return ['ok' => false, 'error' => 'The product image data is invalid.'];
        }

        try {
            $normalized = $mime === 'image/jpeg' || $mime === 'image/jpg'
                ? $binary
                : $this->images->normalizeToGalleryJpeg($binary);
            $this->writeWorkingInput($product, $normalized);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true];
    }

    /**
     * Run a single sequence step using the stored working input, save an admin-only image,
     * and replace the working input with the step output.
     *
     * @param  list<string>  $steps
     * @return array{ok: true, product_image_id: int, step: int, steps: int}|array{ok: false, error: string}
     */
    public function generateStep(Product $product, array $steps, int $stepIndex): array
    {
        $normalizedSteps = $this->normalizeSteps($steps);
        $stepCount = count($normalizedSteps);

        if ($stepCount === 0) {
            return ['ok' => false, 'error' => 'Add at least one instruction step (min 3 characters).'];
        }

        if ($stepIndex < 0 || $stepIndex >= $stepCount) {
            return ['ok' => false, 'error' => 'Invalid sequence step.'];
        }

        if (! $this->gemini->isConfigured()) {
            return ['ok' => false, 'error' => 'Gemini API key is not configured (GEMINI_API_KEY).'];
        }

        $inputBinary = $this->readWorkingInput($product);

        if ($inputBinary === null || $inputBinary === '') {
            return ['ok' => false, 'error' => 'Working source image is missing. Re-run this product.'];
        }

        $stepNumber = $stepIndex + 1;
        $step = $normalizedSteps[$stepIndex];
        $instruction = $this->stepInstruction($step, $stepNumber, $stepCount);

        try {
            $result = $this->gemini->generateImage([
                ['text' => $instruction],
                [
                    'inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data' => base64_encode($inputBinary),
                    ],
                ],
            ], $this->systemPrompt());

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

            AiImagePrompt::remember($step, Auth::id());
            $this->writeWorkingInput($product, $normalized);

            return [
                'ok' => true,
                'product_image_id' => $image->id,
                'step' => $stepNumber,
                'steps' => $stepCount,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function forgetWorkingInput(Product $product): void
    {
        $path = $this->workingInputPath($product);

        if (is_file($path)) {
            @unlink($path);
        }
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

    private function writeWorkingInput(Product $product, string $binary): void
    {
        File::ensureDirectoryExists(dirname($this->workingInputPath($product)));
        file_put_contents($this->workingInputPath($product), $binary);
    }

    private function readWorkingInput(Product $product): ?string
    {
        $path = $this->workingInputPath($product);

        if (! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);

        return $binary === false ? null : $binary;
    }

    private function workingInputPath(Product $product): string
    {
        $userId = Auth::id() ?: 0;

        return storage_path('app/private/bulk-ai/'.$userId.'/'.$product->id.'.bin');
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
