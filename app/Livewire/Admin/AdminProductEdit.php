<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\InteractsWithAdminProductListFilters;
use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductCostHead;
use App\Models\ProductImage;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductDescriptionGenerator;
use App\Services\Admin\ProductImageService;
use App\Services\Admin\ProductPricedImageService;
use App\Services\Admin\ProductUnitCostService;
use App\Support\Fileinfo;
use App\Support\ProductDescriptionHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

#[Layout('components.layouts.admin')]
class AdminProductEdit extends Component
{
    use InteractsWithAdminProductListFilters;
    use WithFileUploads;

    public ?Product $product = null;

    public ?int $category_id = null;

    public string $name = '';

    public string $slug = '';

    public string $sku = '';

    public string $description = '';

    public string $description_bn = '';

    public bool $aiDescriptionGenerating = false;

    public ?string $aiDescriptionError = null;

    public ?string $aiDescriptionMessage = null;

    public string $price = '0';

    public string $purchase_price = '0';

    public string $unit_cost_display = '0';

    public string $commission = '0';

    public ?int $bomMaterialId = null;

    public string $bomQuantity = '1';

    public bool $bomIsPrimary = false;

    public string $costHeadName = '';

    public string $costHeadAmount = '0';

    public string $max_discount = '';

    public string $compare_at_price = '';

    public int $stock_quantity = 0;

    public int $display_order = 0;

    public bool $is_published = false;

    public bool $is_featured = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $newImages = [];

    /** @var array<int, string> */
    public array $pendingAlts = [];

    /** @var array<int, string> */
    public array $imageAlts = [];

    /** @var array<int, string> */
    public array $resizeMaxWidths = [];

    /** @var array<int, string> */
    public array $resizeMaxHeights = [];

    public ?string $message = null;

    /** Set by ensureProductSaved() so uploadImages can redirect after create. */
    public bool $justCreated = false;

    public bool $showAiGenerateModal = false;

    public string $aiPrompt = '';

    /** @var list<array{id: string, mime: string, name: string, version: int, product_image_id?: int|null, sequence_id?: string|null, step_index?: int|null, step_total?: int|null, step_prompt?: string|null, source_image_id?: int|null}> */
    public array $aiCandidates = [];

    public ?string $aiGenerateError = null;

    public bool $aiGenerating = false;

    public bool $showPricedImageModal = false;

    public string $pricedImagePosition = 'top-left';

    public int $pricedImageFont = 56;

    public int $imagesEpoch = 0;

    public function mount(?Product $product = null): void
    {
        $this->hydrateAdminProductListFilters();

        if (! $product?->exists) {
            return;
        }

        $this->product = $product->load([
            'images' => fn ($q) => $q->orderBy('sort_order'),
            'materials',
            'costHeads',
        ]);
        $this->syncImageAlts();
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->sku = (string) ($product->sku ?? '');
        $this->description = (string) ($product->description ?? '');
        $this->description_bn = (string) ($product->description_bn ?? '');
        $this->price = (string) (int) round((float) $product->price);
        $this->purchase_price = (string) (int) round((float) $product->purchase_price);
        $this->unit_cost_display = (string) (int) round($product->effectiveUnitCost());
        $this->commission = (string) (int) round((float) $product->commission);
        $this->max_discount = $product->max_discount !== null
            ? (string) (int) round((float) $product->max_discount)
            : '';
        $this->compare_at_price = $product->compare_at_price !== null
            ? (string) (int) round((float) $product->compare_at_price)
            : '';
        $this->stock_quantity = (int) $product->stock_quantity;
        $this->display_order = (int) $product->display_order;
        $this->is_published = (bool) $product->is_published;
        $this->is_featured = (bool) $product->is_featured;
        $this->fillPricedImageLayout();
    }

    public function title(): string
    {
        return $this->product ? 'Edit '.$this->product->name : 'Create Product';
    }

    public function updatedName(string $value): void
    {
        if ($this->product) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function openAiGenerateModal(): void
    {
        $this->aiGenerateError = null;
        $this->ensureProductSaved();

        if ($this->justCreated) {
            $this->justCreated = false;
        }

        $this->showAiGenerateModal = true;
    }

    public function closeAiGenerateModal(): void
    {
        $this->showAiGenerateModal = false;
        $this->aiGenerateError = null;
        $this->aiGenerating = false;
        $ids = array_column($this->aiCandidates, 'id');
        foreach ($this->aiCandidates as $candidate) {
            $sequenceId = (string) ($candidate['sequence_id'] ?? '');
            if ($sequenceId !== '') {
                $ids[] = $sequenceId.'-source';
            }
        }
        $this->forgetAiCandidateBinaries($ids);
        $this->aiCandidates = [];
        $this->resetValidation(['aiRawImage', 'aiPrompt']);
    }

    public function useRecentPrompt(string $prompt): void
    {
        $this->aiPrompt = $prompt;
        $this->dispatch('ai-prompt-steps-set', steps: [trim($prompt)]);
    }

    public function usePromptGroup(int $groupId): void
    {
        $group = AiPromptGroup::query()
            ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->findOrFail($groupId);

        $steps = $group->stepTexts();

        if ($steps === []) {
            $this->aiGenerateError = 'That prompt group has no steps.';

            return;
        }

        $this->aiPrompt = implode("\n", $steps);
        $this->dispatch('ai-prompt-steps-set', steps: $steps);
    }

    public function openPricedImageModal(ProductPricedImageService $pricedImages): void
    {
        // Only persist when creating; dirty invalid fields on edit must not block the modal.
        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->fillPricedImageLayout($pricedImages);
        $this->showPricedImageModal = true;
        $this->js('document.body.classList.add("overflow-hidden")');
    }

    public function closePricedImageModal(): void
    {
        $this->showPricedImageModal = false;
        $this->js('document.body.classList.remove("overflow-hidden")');
    }

    public function savePricedImageLayout(): void
    {
        $this->validate([
            'pricedImagePosition' => ['required', 'in:'.implode(',', ProductPricedImageService::POSITIONS)],
            'pricedImageFont' => [
                'required',
                'integer',
                'min:'.ProductPricedImageService::FONT_MIN,
                'max:'.ProductPricedImageService::FONT_MAX,
            ],
        ]);

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->product->update([
            'priced_image_layout' => [
                'position' => $this->pricedImagePosition,
                'font' => $this->pricedImageFont,
            ],
        ]);
    }

    public function generatePricedImage(ProductPricedImageService $pricedImages): void
    {
        if (! $this->product) {
            $this->ensureProductSaved();
        }

        try {
            $this->savePricedImageLayout();
            $pricedImages->generate($this->product->fresh(), [
                'position' => $this->pricedImagePosition,
                'font' => $this->pricedImageFont,
            ]);
            $this->product->refresh();
            $this->message = 'Priced image saved.';
        } catch (Throwable $e) {
            $this->addError('pricedImage', $e->getMessage());
        }
    }

    public function deletePricedImage(ProductPricedImageService $pricedImages): void
    {
        if (! $this->product?->priced_image_path) {
            return;
        }

        $pricedImages->clear($this->product);
        $this->product->refresh();
        $this->message = 'Priced image deleted.';
    }

    public function generateDescriptionsFromImage(ProductDescriptionGenerator $generator): void
    {
        $this->aiDescriptionError = null;
        $this->aiDescriptionMessage = null;

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        if (! $this->product) {
            $this->aiDescriptionError = 'Save the product before generating descriptions.';

            return;
        }

        $this->product->loadMissing(['images', 'category']);
        $this->aiDescriptionGenerating = true;

        try {
            $result = $generator->generate($this->product);

            if ($result['description'] !== '') {
                $this->description = $result['description'];
            }

            if ($result['description_bn'] !== '') {
                $this->description_bn = $result['description_bn'];
            }

            $this->aiDescriptionMessage = 'Descriptions generated from the primary product image. Review and save.';
        } catch (Throwable $e) {
            $this->aiDescriptionError = $e->getMessage();
        } finally {
            $this->aiDescriptionGenerating = false;
        }
    }

    /**
     * @param  list<string>|array<int, string>  $steps
     * @return array{ok: bool, id?: string, product_image_id?: int, steps?: int, error?: string}
     */
    public function generateAiImage(
        GeminiClient $gemini,
        string $rawImageBase64 = '',
        string $rawImageMime = 'image/jpeg',
        ?int $sourceImageId = null,
        array $steps = [],
    ): array {
        $this->aiGenerateError = null;

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $normalizedSteps = $this->normalizeAiSteps($steps);

        if ($normalizedSteps === []) {
            $this->addError('aiPrompt', 'Add at least one instruction step (min 3 characters).');

            return ['ok' => false, 'error' => 'Add at least one instruction step (min 3 characters).'];
        }

        $this->aiPrompt = implode("\n", $normalizedSteps);

        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:3', 'max:16000'],
        ]);

        try {
            [$rawImageBase64, $mime] = $this->resolveAiSourceImage($rawImageBase64, $rawImageMime, $sourceImageId);
        } catch (InvalidArgumentException $e) {
            $this->addError('aiRawImage', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->aiGenerating = true;

        try {
            if (! $gemini->isConfigured()) {
                throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
            }

            $currentBase64 = $rawImageBase64;
            $currentMime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            $stepCount = count($normalizedSteps);
            $sequenceId = (string) Str::uuid();
            $systemPrompt = $this->aiEditSystemPrompt();
            $createdIds = [];

            $this->persistAiCandidateBinary($sequenceId.'-source', base64_decode($currentBase64, true) ?: '', $currentMime);

            foreach ($normalizedSteps as $index => $step) {
                $stepNumber = $index + 1;
                $instruction = $this->aiStepInstruction($step, $stepNumber, $stepCount);

                $result = $gemini->generateImage([
                    ['text' => $instruction],
                    [
                        'inline_data' => [
                            'mime_type' => $currentMime,
                            'data' => $currentBase64,
                        ],
                    ],
                ], $systemPrompt);

                $binary = base64_decode((string) $result['base64'], true);

                if ($binary === false || $binary === '') {
                    throw new RuntimeException("Gemini returned an empty image on step {$stepNumber}.");
                }

                $candidateId = (string) Str::uuid();
                $this->persistAiCandidateBinary($candidateId, $binary, (string) ($result['mime'] ?? 'image/jpeg'));

                $productImage = $this->storeCandidateAsProductImage($candidateId, adminOnly: true, altSuffix: "AI step {$stepNumber}");

                $this->aiCandidates[] = [
                    'id' => $candidateId,
                    'mime' => 'image/jpeg',
                    'name' => $stepCount > 1
                        ? "ai-step-{$stepNumber}-of-{$stepCount}.jpg"
                        : 'ai-generated-'.(count($this->aiCandidates) + 1).'.jpg',
                    'version' => 1,
                    'product_image_id' => $productImage?->id,
                    'sequence_id' => $sequenceId,
                    'step_index' => $index,
                    'step_total' => $stepCount,
                    'step_prompt' => $step,
                    'source_image_id' => $sourceImageId,
                ];
                $createdIds[] = $candidateId;

                AiImagePrompt::remember($step, Auth::id());

                $normalized = $this->readAiCandidateBinary($candidateId) ?? '';
                $currentBase64 = base64_encode($normalized);
                $currentMime = 'image/jpeg';
            }

            $this->refreshImages();
            $this->syncImageAlts();
            $this->message = $stepCount > 1
                ? "AI sequence saved {$stepCount} admin-only images (one per step)."
                : 'AI image saved (admin only — not shown on the storefront).';

            return [
                'ok' => true,
                'id' => $createdIds[array_key_last($createdIds)] ?? null,
                'ids' => $createdIds,
                'product_image_id' => collect($this->aiCandidates)->last()['product_image_id'] ?? null,
                'steps' => $stepCount,
            ];
        } catch (Throwable $e) {
            $this->aiGenerateError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $this->aiGenerating = false;
        }
    }

    /**
     * Retry a single sequence step using the previous step (or original source) as input.
     *
     * @return array{ok: bool, id?: string, product_image_id?: int, error?: string}
     */
    public function retryAiCandidateStep(
        GeminiClient $gemini,
        string $candidateId,
        string $rawImageBase64 = '',
        string $rawImageMime = 'image/jpeg',
        ?int $sourceImageId = null,
    ): array {
        $this->aiGenerateError = null;

        $index = collect($this->aiCandidates)->search(fn (array $row) => ($row['id'] ?? null) === $candidateId);

        if ($index === false) {
            $this->aiGenerateError = 'Generated image not found in this session.';

            return ['ok' => false, 'error' => $this->aiGenerateError];
        }

        /** @var array{id: string, step_index?: int|null, step_total?: int|null, step_prompt?: string|null, sequence_id?: string|null, source_image_id?: int|null, product_image_id?: int|null, version?: int} $candidate */
        $candidate = $this->aiCandidates[$index];
        $stepPrompt = trim((string) ($candidate['step_prompt'] ?? $this->aiPrompt));

        if (strlen($stepPrompt) < 3) {
            $this->aiGenerateError = 'This step has no instruction to retry.';

            return ['ok' => false, 'error' => $this->aiGenerateError];
        }

        $stepIndex = (int) ($candidate['step_index'] ?? 0);
        $stepTotal = max(1, (int) ($candidate['step_total'] ?? 1));
        $sequenceId = (string) ($candidate['sequence_id'] ?? '');

        try {
            [$inputBase64, $inputMime] = $this->resolveRetryInputImage(
                $candidate,
                $rawImageBase64,
                $rawImageMime,
                $sourceImageId ?? (isset($candidate['source_image_id']) ? (int) $candidate['source_image_id'] : null),
            );
        } catch (InvalidArgumentException $e) {
            $this->aiGenerateError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->aiGenerating = true;

        try {
            if (! $gemini->isConfigured()) {
                throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
            }

            $instruction = $this->aiStepInstruction($stepPrompt, $stepIndex + 1, $stepTotal);
            $result = $gemini->generateImage([
                ['text' => $instruction],
                [
                    'inline_data' => [
                        'mime_type' => $inputMime === 'image/jpg' ? 'image/jpeg' : $inputMime,
                        'data' => $inputBase64,
                    ],
                ],
            ], $this->aiEditSystemPrompt());

            $binary = base64_decode((string) $result['base64'], true);

            if ($binary === false || $binary === '') {
                throw new RuntimeException('Gemini returned an empty image.');
            }

            $this->persistAiCandidateBinary($candidateId, $binary, (string) ($result['mime'] ?? 'image/jpeg'));

            $updated = $candidate;
            $updated['mime'] = 'image/jpeg';
            $updated['version'] = ((int) ($candidate['version'] ?? 1)) + 1;
            $updated['step_prompt'] = $stepPrompt;

            $productImageId = (int) ($candidate['product_image_id'] ?? 0);

            if ($productImageId > 0) {
                $this->replaceLinkedProductImage($productImageId, $candidateId);
            } else {
                $productImage = $this->storeCandidateAsProductImage(
                    $candidateId,
                    adminOnly: true,
                    altSuffix: 'AI step '.($stepIndex + 1),
                );
                $updated['product_image_id'] = $productImage?->id;
            }

            // Reassign the whole row so Livewire marks aiCandidates dirty after nested updates.
            $this->aiCandidates[$index] = $updated;

            AiImagePrompt::remember($stepPrompt, Auth::id());
            $this->refreshImages();
            $this->syncImageAlts();
            $this->message = 'Step '.($stepIndex + 1).' regenerated (admin only).';

            return [
                'ok' => true,
                'id' => $candidateId,
                'product_image_id' => $this->aiCandidates[$index]['product_image_id'] ?? null,
            ];
        } catch (Throwable $e) {
            $this->aiGenerateError = $e->getMessage();

            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $this->aiGenerating = false;
        }
    }

    private function aiEditSystemPrompt(): string
    {
        return 'You edit product photos for a Bangladeshi jewelry e-commerce catalog. '
            .'You receive the current product photo and ONE editing instruction. '
            .'Apply only that instruction to the provided photo. '
            .'Preserve the same product identity, jewellery piece, shape, and materials unless the instruction explicitly changes them. '
            .'Do not invent a different product, replace the jewellery with a new design, or ignore the reference photo. '
            .'Return one edited image.';
    }

    private function aiStepInstruction(string $step, int $stepNumber, int $stepCount): string
    {
        if ($stepCount <= 1) {
            return $step;
        }

        return "Step {$stepNumber} of {$stepCount} — continue editing THIS same product photo (do not start a new product): {$step}";
    }

    /**
     * Prefer the previous step's durable product image when private session bins are gone.
     * Never fall back to the original sequence source for step N>0 — that silently re-runs
     * the wrong prompt input and looks like a broken retry.
     *
     * @param  array{sequence_id?: string|null, step_index?: int|null, source_image_id?: int|null, product_image_id?: int|null}  $candidate
     * @return array{0: string, 1: string}
     */
    private function resolveRetryInputImage(
        array $candidate,
        string $rawImageBase64,
        string $rawImageMime,
        ?int $sourceImageId,
    ): array {
        $stepIndex = (int) ($candidate['step_index'] ?? 0);
        $sequenceId = (string) ($candidate['sequence_id'] ?? '');

        if ($stepIndex > 0 && $sequenceId !== '') {
            $previous = collect($this->aiCandidates)
                ->first(fn (array $row) => ($row['sequence_id'] ?? null) === $sequenceId
                    && (int) ($row['step_index'] ?? -1) === ($stepIndex - 1));

            if (is_array($previous)) {
                $binary = $this->readAiCandidateBinary((string) $previous['id']);

                if ($binary !== null && $binary !== '') {
                    return [base64_encode($binary), 'image/jpeg'];
                }

                $previousProductImageId = (int) ($previous['product_image_id'] ?? 0);

                if ($previousProductImageId > 0) {
                    return $this->resolveAiSourceImage('', '', $previousProductImageId);
                }
            }

            throw new InvalidArgumentException(
                'The previous step image is no longer available. Re-run the full sequence, then retry.'
            );
        }

        if ($sequenceId !== '') {
            $sourceBinary = $this->readAiCandidateBinary($sequenceId.'-source');

            if ($sourceBinary !== null && $sourceBinary !== '') {
                return [base64_encode($sourceBinary), 'image/jpeg'];
            }
        }

        return $this->resolveAiSourceImage($rawImageBase64, $rawImageMime, $sourceImageId);
    }

    /**
     * @param  list<string>|array<int, mixed>  $steps
     * @return list<string>
     */
    private function normalizeAiSteps(array $steps): array
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

        if ($normalized === [] && trim((string) $this->aiPrompt) !== '') {
            $fallback = trim((string) $this->aiPrompt);

            if (strlen($fallback) >= 3) {
                $normalized[] = strlen($fallback) > 4000 ? substr($fallback, 0, 4000) : $fallback;
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array{0: string, 1: string} [base64, mime]
     */
    private function resolveAiSourceImage(string $rawImageBase64, string $rawImageMime, ?int $sourceImageId): array
    {
        if ($sourceImageId) {
            $image = $this->findOwnedImage($sourceImageId);
            $absolute = public_path(ltrim(str_replace('\\', '/', (string) $image->path), '/'));

            if (! is_file($absolute) || ! is_readable($absolute)) {
                throw new InvalidArgumentException('The selected product image file is not readable.');
            }

            $binary = file_get_contents($absolute);

            if ($binary === false || $binary === '') {
                throw new InvalidArgumentException('The selected product image is empty.');
            }

            if (strlen($binary) > 8 * 1024 * 1024) {
                throw new InvalidArgumentException('The selected product image must be 8 MB or smaller.');
            }

            $info = @getimagesizefromstring($binary);
            $mime = is_array($info) ? (string) ($info['mime'] ?? 'image/jpeg') : 'image/jpeg';

            return [base64_encode($binary), $mime];
        }

        $rawImageBase64 = trim($rawImageBase64);

        if ($rawImageBase64 === '') {
            throw new InvalidArgumentException('Choose a raw photo or one of the existing product images.');
        }

        $binary = base64_decode($rawImageBase64, true);

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('The raw photo data is invalid.');
        }

        if (strlen($binary) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('The raw photo must be 8 MB or smaller.');
        }

        $mime = strtolower(trim($rawImageMime));

        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $mime = 'image/jpeg';
        }

        return [$rawImageBase64, $mime];
    }

    public function updateAiCandidate(string $id, string $mime, string $base64): void
    {
        $this->aiGenerateError = null;
        $binary = base64_decode($base64, true);

        if ($binary === false || $binary === '') {
            $this->aiGenerateError = 'Edited image data is invalid.';

            return;
        }

        if (strlen($binary) > 8 * 1024 * 1024) {
            $this->aiGenerateError = 'Edited image must be 8 MB or smaller.';

            return;
        }

        foreach ($this->aiCandidates as $index => $candidate) {
            if (($candidate['id'] ?? null) !== $id) {
                continue;
            }

            $mime = $mime !== '' ? $mime : 'image/jpeg';
            $this->persistAiCandidateBinary($id, $binary, $mime);
            $this->aiCandidates[$index]['mime'] = 'image/jpeg';
            $this->aiCandidates[$index]['name'] = preg_replace('/\.\w+$/', '.jpg', (string) $candidate['name']) ?: 'ai-edited.jpg';
            $this->aiCandidates[$index]['version'] = ((int) ($candidate['version'] ?? 1)) + 1;

            $productImageId = (int) ($candidate['product_image_id'] ?? 0);

            if ($productImageId > 0 && $this->product) {
                $this->replaceLinkedProductImage($productImageId, $id);
            }

            return;
        }

        $this->aiGenerateError = 'Generated image not found in this session.';
    }

    public function removeAiCandidate(string $id, ProductImageService $images): void
    {
        $candidate = collect($this->aiCandidates)->firstWhere('id', $id);
        $productImageId = (int) ($candidate['product_image_id'] ?? 0);

        if ($productImageId > 0 && $this->product) {
            $image = ProductImage::query()
                ->where('product_id', $this->product->id)
                ->whereKey($productImageId)
                ->where('is_admin_only', true)
                ->first();

            if ($image) {
                $images->delete($image);
                $this->refreshImages();
                $this->syncImageAlts();
            }
        }

        $this->forgetAiCandidateBinaries([$id]);
        $this->aiCandidates = array_values(array_filter(
            $this->aiCandidates,
            fn (array $row) => ($row['id'] ?? null) !== $id,
        ));
    }

    public function promoteAiCandidate(string $id, ProductImageService $images): void
    {
        $this->aiGenerateError = null;

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $candidate = collect($this->aiCandidates)->firstWhere('id', $id);

        if (! is_array($candidate)) {
            $this->aiGenerateError = 'Generated image not found in this session.';

            return;
        }

        $productImageId = (int) ($candidate['product_image_id'] ?? 0);

        if ($productImageId > 0) {
            $image = $this->findOwnedImage($productImageId);
            $image->update(['is_admin_only' => false]);
            $this->refreshImages();
            $this->syncImageAlts();
            $this->removeAiCandidate($id, $images);
            $this->message = 'AI image is now public on the product gallery.';

            return;
        }

        $productImage = $this->storeCandidateAsProductImage($id, adminOnly: false);

        if (! $productImage) {
            return;
        }

        $this->removeAiCandidate($id, $images);
        $this->message = 'AI image added to product gallery.';
    }

    private function storeCandidateAsProductImage(string $id, bool $adminOnly, ?string $altSuffix = null): ?ProductImage
    {
        $candidate = collect($this->aiCandidates)->firstWhere('id', $id);
        $binary = $this->readAiCandidateBinary($id);

        if ($binary === null || $binary === '') {
            $this->aiGenerateError = 'Generated image data is invalid.';

            return null;
        }

        $name = is_array($candidate)
            ? (string) ($candidate['name'] ?? 'ai-generated.jpg')
            : 'ai-generated.jpg';

        $tempPath = tempnam(sys_get_temp_dir(), 'aiimg_');

        if ($tempPath === false) {
            $this->aiGenerateError = 'Could not create a temporary file.';

            return null;
        }

        $pathWithExt = $tempPath.'.jpg';
        rename($tempPath, $pathWithExt);
        file_put_contents($pathWithExt, $binary);

        try {
            $upload = new UploadedFile(
                $pathWithExt,
                $name,
                'image/jpeg',
                null,
                true,
            );

            $alt = $this->product->name;
            if ($adminOnly) {
                $alt .= $altSuffix ? ' ('.$altSuffix.')' : ' (AI)';
            }

            return app(ProductImageService::class)->store(
                $this->product,
                $upload,
                $alt,
                $adminOnly,
            );
        } finally {
            if (is_file($pathWithExt)) {
                @unlink($pathWithExt);
            }
        }
    }

    private function replaceLinkedProductImage(int $productImageId, string $candidateId): void
    {
        $binary = $this->readAiCandidateBinary($candidateId);

        if ($binary === null || $binary === '') {
            return;
        }

        $image = ProductImage::query()
            ->where('product_id', $this->product->id)
            ->whereKey($productImageId)
            ->first();

        if (! $image) {
            return;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'aiimg_');

        if ($tempPath === false) {
            return;
        }

        $pathWithExt = $tempPath.'.jpg';
        rename($tempPath, $pathWithExt);
        file_put_contents($pathWithExt, $binary);

        try {
            $upload = new UploadedFile($pathWithExt, 'ai-edited.jpg', 'image/jpeg', null, true);
            app(ProductImageService::class)->replace($image, $upload);
            $this->refreshImages();
            $this->syncImageAlts();
            $this->message = 'Admin-only AI image updated.';
        } finally {
            if (is_file($pathWithExt)) {
                @unlink($pathWithExt);
            }
        }
    }

    /**
     * @param  list<string|null>  $ids
     */
    private function forgetAiCandidateBinaries(array $ids): void
    {
        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            foreach ([$this->aiCandidateBinPath($id), $this->aiCandidateMetaPath($id)] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function persistAiCandidateBinary(string $id, string $binary, string $mime): void
    {
        $normalized = app(ProductImageService::class)->normalizeToGalleryJpeg($binary);

        File::ensureDirectoryExists($this->aiCandidateDirectory());
        file_put_contents($this->aiCandidateBinPath($id), $normalized);
        file_put_contents($this->aiCandidateMetaPath($id), json_encode([
            'mime' => 'image/jpeg',
            'source_mime' => $mime !== '' ? $mime : 'image/jpeg',
            'updated_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function readAiCandidateBinary(string $id): ?string
    {
        $path = $this->aiCandidateBinPath($id);

        if (! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);

        return $binary === false ? null : $binary;
    }

    private function aiCandidateDirectory(): string
    {
        $userId = Auth::id() ?: 0;

        return storage_path('app/private/ai-candidates/'.$userId);
    }

    private function aiCandidateBinPath(string $id): string
    {
        return $this->aiCandidateDirectory().DIRECTORY_SEPARATOR.$id.'.bin';
    }

    private function aiCandidateMetaPath(string $id): string
    {
        return $this->aiCandidateDirectory().DIRECTORY_SEPARATOR.$id.'.json';
    }

    public function save(): void
    {
        $existingPrice = $this->product?->price;
        $existingCompareAtPrice = $this->product?->compare_at_price;
        $this->ensureProductSaved();

        if ($this->product?->priced_image_path
            && ((float) $existingPrice !== (float) $this->product->price
                || (float) $existingCompareAtPrice !== (float) $this->product->compare_at_price)) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        if ($this->justCreated) {
            $this->justCreated = false;
            $this->redirect(route('admin.products.edit', $this->product), navigate: true);

            return;
        }

        if (! str_starts_with((string) $this->message, 'Warning:')) {
            $this->message = 'Product saved.';
        }
    }

    /**
     * Create or update the product without redirecting. Call before uploading images on create.
     */
    public function ensureProductSaved(): void
    {
        $this->message = null;
        $wasCreate = $this->product === null;
        $this->persistProduct();
        $this->justCreated = $wasCreate;
    }

    public function uploadImages(ProductImageService $images): void
    {
        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->validate([
            'newImages' => ['required', 'array', 'min:1'],
            'newImages.*' => Fileinfo::storedImageItemRules(5120),
        ]);

        $count = count($this->newImages);
        $shouldRedirect = $this->justCreated;
        $this->justCreated = false;

        foreach ($this->newImages as $index => $file) {
            $alt = trim((string) ($this->pendingAlts[$index] ?? ''));
            $images->store($this->product, $file, $alt !== '' ? $alt : null);
        }

        $this->newImages = [];
        $this->pendingAlts = [];
        $this->refreshImages();
        $this->syncImageAlts();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        if ($shouldRedirect) {
            $this->redirect(route('admin.products.edit', $this->product), navigate: true);

            return;
        }

        $this->message = $count === 1 ? 'Image uploaded.' : "{$count} images uploaded.";
    }

    public function replaceEditedImage(
        int $imageId,
        string $imageBase64 = '',
        string $mime = 'image/jpeg',
    ): array {
        $image = $this->findOwnedImage($imageId);
        $wasPrimary = $image->is_primary;
        $oldPath = (string) $image->path;

        $imageBase64 = trim($imageBase64);

        if ($imageBase64 === '') {
            $this->addError('editedImage', 'The edited image is required.');

            return [];
        }

        $binary = base64_decode($imageBase64, true);

        if ($binary === false || $binary === '') {
            $this->addError('editedImage', 'The edited image data is invalid.');

            return [];
        }

        if (strlen($binary) > 8 * 1024 * 1024) {
            $this->addError('editedImage', 'The edited image must be 8 MB or smaller.');

            return [];
        }

        $mime = strtolower(trim($mime));

        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
            $mime = 'image/jpeg';
        }

        $extension = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'editimg_');

        if ($tempPath === false) {
            $this->addError('editedImage', 'Could not create a temporary file.');

            return [];
        }

        $pathWithExt = $tempPath.'.'.$extension;
        rename($tempPath, $pathWithExt);
        file_put_contents($pathWithExt, $binary);

        try {
            $upload = new UploadedFile(
                $pathWithExt,
                'edited-'.$imageId.'.'.$extension,
                $mime === 'image/jpg' ? 'image/jpeg' : $mime,
                null,
                true,
            );

            app(ProductImageService::class)->replace($image, $upload);
        } finally {
            if (is_file($pathWithExt)) {
                @unlink($pathWithExt);
            }
        }

        $image->refresh();

        if ($image->path === $oldPath) {
            $this->addError('editedImage', 'The image file was not replaced. Please try again.');

            return [];
        }

        $this->imagesEpoch++;
        $this->refreshImages();
        $this->syncImageAlts();

        if ($wasPrimary && $this->product?->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
            $this->product->refresh();
            $this->refreshImages();
        }

        $this->message = 'Image updated.';

        $previewUrl = route('admin.products.images.raw', [$this->product, $image])
            .'?v='.rawurlencode(md5((string) $image->path).'-'.$this->imagesEpoch);

        return [
            'id' => $image->id,
            'path' => $image->path,
            'url' => $previewUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistProduct(): array
    {
        $slugUnique = $this->product
            ? 'unique:products,slug,'.$this->product->id
            : 'unique:products,slug';

        $validated = $this->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $slugUnique],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'description_bn' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'commission' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && (float) $value <= (float) $this->price) {
                    $fail('Regular price must be greater than selling price.');
                }
            }],
            'stock_quantity' => ['integer', 'min:0'],
            'display_order' => ['integer', 'min:0', 'max:32767'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
        ]);

        if ($validated['slug'] === '') {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated['price'] = (int) round((float) $validated['price']);
        $validated['purchase_price'] = (int) round((float) ($validated['purchase_price'] ?? 0));
        $validated['commission'] = (int) round((float) ($validated['commission'] ?? 0));
        $validated['max_discount'] = isset($validated['max_discount']) && $validated['max_discount'] !== ''
            ? (int) round((float) $validated['max_discount'])
            : null;
        $validated['compare_at_price'] = isset($validated['compare_at_price']) && $validated['compare_at_price'] !== ''
            ? (int) round((float) $validated['compare_at_price'])
            : null;
        $validated['sku'] = $validated['sku'] !== '' ? $validated['sku'] : null;
        $en = ProductDescriptionHtml::sanitize($validated['description'] ?? '');
        $bn = ProductDescriptionHtml::sanitize($validated['description_bn'] ?? '');
        $validated['description'] = $en !== '' ? $en : null;
        $validated['description_bn'] = $bn !== '' ? $bn : null;

        if ($this->product) {
            $this->product->update($validated);
        } else {
            $validated['unit_cost'] = $validated['purchase_price'];
            $this->product = Product::query()->create($validated);
        }

        $this->product = app(ProductUnitCostService::class)->recalculate($this->product->fresh());
        $this->purchase_price = (string) (int) round((float) $this->product->purchase_price);
        $this->unit_cost_display = (string) (int) round($this->product->effectiveUnitCost());

        $marginCap = (float) $validated['price'] - $this->product->effectiveUnitCost();
        if ($validated['max_discount'] !== null && $validated['max_discount'] > $marginCap) {
            $this->message = 'Warning: max discount (৳'.number_format($validated['max_discount'], 0).') exceeds unit margin (৳'.number_format(max(0, $marginCap), 0).'). Saved anyway.';
        }

        return $validated;
    }

    public function addBomMaterial(ProductUnitCostService $costs): void
    {
        $this->ensureProductSaved();

        $validated = $this->validate([
            'bomMaterialId' => ['required', 'integer', 'exists:materials,id'],
            'bomQuantity' => ['required', 'numeric', 'gt:0'],
            'bomIsPrimary' => ['boolean'],
        ]);

        if ($validated['bomIsPrimary']) {
            $this->product->materials()->newPivotStatement()
                ->where('product_id', $this->product->id)
                ->update(['is_primary' => false]);
        }

        $this->product->materials()->syncWithoutDetaching([
            (int) $validated['bomMaterialId'] => [
                'quantity' => round((float) $validated['bomQuantity'], 3),
                'is_primary' => (bool) $validated['bomIsPrimary'],
            ],
        ]);

        $this->refreshBom($costs);
        $this->bomMaterialId = null;
        $this->bomQuantity = '1';
        $this->bomIsPrimary = false;
        $this->message = 'Material linked and costs recalculated.';
    }

    public function updateBomQuantity(int $materialId, string $quantity, ProductUnitCostService $costs): void
    {
        if (! $this->product) {
            return;
        }

        $qty = max(0.001, (float) $quantity);
        $this->product->materials()->updateExistingPivot($materialId, [
            'quantity' => round($qty, 3),
        ]);
        $this->refreshBom($costs);
    }

    public function setBomPrimary(int $materialId, ProductUnitCostService $costs): void
    {
        if (! $this->product) {
            return;
        }

        $this->product->materials()->newPivotStatement()
            ->where('product_id', $this->product->id)
            ->update(['is_primary' => false]);

        $this->product->materials()->updateExistingPivot($materialId, [
            'is_primary' => true,
        ]);
        $this->refreshBom($costs);
    }

    public function removeBomMaterial(int $materialId, ProductUnitCostService $costs): void
    {
        if (! $this->product) {
            return;
        }

        $this->product->materials()->detach($materialId);
        $this->refreshBom($costs);
        $this->message = 'Material removed and costs recalculated.';
    }

    public function addCostHead(ProductUnitCostService $costs): void
    {
        $this->ensureProductSaved();

        $validated = $this->validate([
            'costHeadName' => ['required', 'string', 'max:120'],
            'costHeadAmount' => ['required', 'numeric', 'min:0'],
        ]);

        ProductCostHead::query()->create([
            'product_id' => $this->product->id,
            'name' => $validated['costHeadName'],
            'amount' => round((float) $validated['costHeadAmount'], 2),
            'sort_order' => (int) $this->product->costHeads()->max('sort_order') + 1,
        ]);

        $this->refreshBom($costs);
        $this->costHeadName = '';
        $this->costHeadAmount = '0';
        $this->message = 'Cost head added and unit cost recalculated.';
    }

    public function removeCostHead(int $headId, ProductUnitCostService $costs): void
    {
        if (! $this->product) {
            return;
        }

        ProductCostHead::query()
            ->where('product_id', $this->product->id)
            ->whereKey($headId)
            ->delete();

        $this->refreshBom($costs);
        $this->message = 'Cost head removed and unit cost recalculated.';
    }

    private function refreshBom(ProductUnitCostService $costs): void
    {
        $this->product = $costs->recalculate($this->product->fresh());
        $this->product->load(['materials', 'costHeads', 'images' => fn ($q) => $q->orderBy('sort_order')]);
        $this->purchase_price = (string) (int) round((float) $this->product->purchase_price);
        $this->unit_cost_display = (string) (int) round($this->product->effectiveUnitCost());
    }

    public function delete(ProductImageService $images): void
    {
        if (! $this->product) {
            return;
        }

        $images->deleteProduct($this->product);
        $this->redirect(route('admin.products'), navigate: true);
    }

    public function persistImageAlt(int $imageId): void
    {
        $alt = trim((string) ($this->imageAlts[$imageId] ?? ''));

        $this->findOwnedImage($imageId)->update([
            'alt' => $alt !== '' ? $alt : $this->product->name,
        ]);
    }

    public function deleteImage(int $imageId, ProductImageService $images): void
    {
        $image = $this->findOwnedImage($imageId);
        $images->delete($image);
        $this->refreshImages();
        $this->syncImageAlts();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        $this->message = 'Image removed.';
    }

    public function setPrimaryImage(int $imageId, ProductImageService $images): void
    {
        $image = $this->findOwnedImage($imageId);

        if ($image->is_admin_only) {
            $this->message = 'Admin-only AI images cannot be the storefront primary.';

            return;
        }

        $images->setPrimary($image);
        $this->refreshImages();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        $this->message = 'Primary image updated.';
    }

    public function moveImageEarlier(int $imageId, ProductImageService $images): void
    {
        $images->moveEarlier($this->findOwnedImage($imageId));
        $this->refreshImages();
    }

    public function moveImageLater(int $imageId, ProductImageService $images): void
    {
        $images->moveLater($this->findOwnedImage($imageId));
        $this->refreshImages();
    }

    public function resizeImage(int $imageId, ProductImageService $images): void
    {
        $this->validate([
            "resizeMaxWidths.{$imageId}" => ['required', 'integer', 'min:1', 'max:4000'],
            "resizeMaxHeights.{$imageId}" => ['required', 'integer', 'min:1', 'max:4000'],
        ], [], [
            "resizeMaxWidths.{$imageId}" => 'max width',
            "resizeMaxHeights.{$imageId}" => 'max height',
        ]);

        $image = $this->findOwnedImage($imageId);
        $maxWidth = (int) $this->resizeMaxWidths[$imageId];
        $maxHeight = (int) $this->resizeMaxHeights[$imageId];
        $wasPrimary = $image->is_primary;
        $beforePath = $image->path;

        try {
            $resized = $images->resize($image, $maxWidth, $maxHeight);
        } catch (Throwable $e) {
            $this->addError("resizeMaxWidths.{$imageId}", $e->getMessage());

            return;
        }

        $this->refreshImages();
        $this->syncImageAlts();

        if ($resized->path === $beforePath) {
            $this->message = 'Image already within those dimensions — nothing changed.';

            return;
        }

        if ($wasPrimary && $this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
            $this->product->refresh();
        }

        $this->message = 'Image resized.';
    }

    public function render()
    {
        $this->rememberAdminProductListFilters();

        $promptGroups = AiPromptGroup::query()
            ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('livewire.admin.admin-product-edit', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'materialsForBom' => Material::query()->orderBy('name')->get(['id', 'name', 'unit', 'unit_cost']),
            'aiPromptGroups' => $promptGroups,
            'geminiConfigured' => app(GeminiClient::class)->isConfigured(),
            'hasBomMaterials' => (bool) $this->product?->materials?->isNotEmpty(),
            'listFilters' => $this->currentAdminProductListFilters(),
        ])->title($this->title());
    }

    private function findOwnedImage(int $imageId): ProductImage
    {
        return ProductImage::query()
            ->where('product_id', $this->product->id)
            ->whereKey($imageId)
            ->firstOrFail();
    }

    private function refreshImages(): void
    {
        if (! $this->product) {
            return;
        }

        $this->product->unsetRelation('images');
        $this->product->unsetRelation('listingImage');
        $this->product->load(['images' => fn ($q) => $q->orderBy('sort_order')]);
    }

    private function syncImageAlts(): void
    {
        $this->imageAlts = $this->product->images
            ->mapWithKeys(fn (ProductImage $image) => [$image->id => (string) ($image->alt ?? '')])
            ->all();

        foreach ($this->product->images as $image) {
            $this->resizeMaxWidths[$image->id] ??= (string) ProductImageService::EDGE_LG;
            $this->resizeMaxHeights[$image->id] ??= (string) ProductImageService::EDGE_LG;
        }
    }

    private function fillPricedImageLayout(?ProductPricedImageService $pricedImages = null): void
    {
        $layout = $pricedImages?->normalizeLayout($this->product?->priced_image_layout ?? [])
            ?? app(ProductPricedImageService::class)->normalizeLayout($this->product?->priced_image_layout ?? []);

        $this->pricedImagePosition = (string) $layout['position'];
        $this->pricedImageFont = min(
            ProductPricedImageService::FONT_MAX,
            max(ProductPricedImageService::FONT_MIN, (int) $layout['font']),
        );
    }
}
