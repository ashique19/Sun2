<?php

namespace App\Services\Admin;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiClient
{
    public function isConfigured(): bool
    {
        return $this->apiKeys() !== [];
    }

    /**
     * @return list<string>
     */
    public function apiKeys(): array
    {
        $keys = [];

        foreach ([
            config('gemini.api_key'),
            ...(array) config('gemini.api_keys', []),
        ] as $key) {
            if (! is_string($key)) {
                continue;
            }

            $key = trim($key);

            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function textModels(): array
    {
        return $this->uniqueModels([
            config('gemini.model'),
            ...(array) config('gemini.models', []),
        ], 'gemini-2.5-flash');
    }

    /**
     * @return list<string>
     */
    public function imageModels(): array
    {
        return $this->uniqueModels([
            config('gemini.image_model'),
            ...(array) config('gemini.image_models', []),
        ], 'gemini-2.5-flash-image');
    }

    /**
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>
     */
    public function generateJson(string $systemPrompt, string $userPrompt, array $generationConfig = []): array
    {
        return $this->generateJsonFromParts($systemPrompt, [
            ['text' => $userPrompt],
        ], $generationConfig);
    }

    /**
     * Multimodal JSON generation. Each part is either:
     * - ['text' => string]
     * - ['inline_data' => ['mime_type' => string, 'data' => base64-string]]
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>
     */
    public function generateJsonFromParts(string $systemPrompt, array $parts, array $generationConfig = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        if ($parts === []) {
            throw new RuntimeException('Gemini request parts cannot be empty.');
        }

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => array_merge([
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ], $generationConfig),
        ];

        $response = $this->requestWithFailover(
            label: 'Gemini API',
            models: $this->textModels(),
            timeout: (int) config('gemini.timeout', 20),
            payload: $payload,
        );

        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            Log::warning('Gemini JSON decode failed.', ['text' => $text]);
            throw new RuntimeException('Gemini returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * Generate / edit an image from multimodal parts (text + optional inline image).
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array{mime: string, base64: string}
     */
    public function generateImage(array $parts, ?string $systemPrompt = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        if ($parts === []) {
            throw new RuntimeException('Gemini image request parts cannot be empty.');
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
            ],
        ];

        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $payload['system_instruction'] = [
                'parts' => [['text' => trim($systemPrompt)]],
            ];
        }

        $response = $this->requestWithFailover(
            label: 'Gemini image API',
            models: $this->imageModels(),
            timeout: (int) config('gemini.image_timeout', 90),
            payload: $payload,
            requireInlineImage: true,
        );

        $responseParts = data_get($response->json(), 'candidates.0.content.parts', []);

        if (! is_array($responseParts)) {
            throw new RuntimeException('Gemini image response had no content parts.');
        }

        foreach ($responseParts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if (! is_array($inline)) {
                continue;
            }

            $mime = (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png');
            $data = (string) ($inline['data'] ?? '');

            if ($data === '') {
                continue;
            }

            return [
                'mime' => $mime !== '' ? $mime : 'image/png',
                'base64' => $data,
            ];
        }

        throw new RuntimeException('Gemini did not return an image.');
    }

    /**
     * Try API keys × models in serial until one succeeds.
     *
     * @param  list<string>  $models
     * @param  array<string, mixed>  $payload
     */
    private function requestWithFailover(
        string $label,
        array $models,
        int $timeout,
        array $payload,
        bool $requireInlineImage = false,
    ): Response {
        $keys = $this->apiKeys();
        $models = array_values(array_filter($models, fn ($model) => is_string($model) && trim($model) !== ''));

        if ($keys === [] || $models === []) {
            throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        $baseUrl = rtrim((string) config('gemini.base_url'), '/');
        $errors = [];
        $attempt = 0;

        foreach ($keys as $keyIndex => $apiKey) {
            $skipRemainingModelsForKey = false;

            foreach ($models as $modelIndex => $model) {
                if ($skipRemainingModelsForKey) {
                    break;
                }

                $attempt++;
                $url = $baseUrl.'/models/'.$model.':generateContent';

                try {
                    $response = Http::timeout($timeout)
                        ->withQueryParameters(['key' => $apiKey])
                        ->acceptJson()
                        ->asJson()
                        ->post($url, $payload);
                } catch (\Throwable $e) {
                    $errors[] = $this->truncateError($e->getMessage());
                    Log::warning('Gemini request failed; trying next credential/model.', [
                        'label' => $label,
                        'model' => $model,
                        'key_index' => $keyIndex,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($response->successful()) {
                    if ($requireInlineImage && ! $this->responseContainsInlineImage($response)) {
                        $errors[] = "{$model}: no image in response";
                        Log::warning('Gemini returned success without an image; trying next.', [
                            'label' => $label,
                            'model' => $model,
                            'key_index' => $keyIndex,
                            'attempt' => $attempt,
                        ]);

                        continue;
                    }

                    if ($attempt > 1) {
                        Log::info('Gemini failover succeeded.', [
                            'label' => $label,
                            'model' => $model,
                            'key_index' => $keyIndex,
                            'attempt' => $attempt,
                        ]);
                    }

                    return $response;
                }

                $status = $response->status();
                $body = $response->body();
                $formatted = $this->formatHttpError($label, $status, $body);
                $errors[] = $formatted;

                Log::warning('Gemini request rejected; evaluating failover.', [
                    'label' => $label,
                    'model' => $model,
                    'key_index' => $keyIndex,
                    'attempt' => $attempt,
                    'status' => $status,
                ]);

                if ($this->isAuthFailure($status, $body)) {
                    // Bad / revoked key — skip other models for this key.
                    $skipRemainingModelsForKey = true;

                    continue;
                }

                if ($this->shouldTryNext($status, $body)) {
                    continue;
                }

                // Non-retryable client error (e.g. bad prompt) — stop early.
                throw new RuntimeException($formatted);
            }
        }

        $summary = $errors === []
            ? "{$label} failed for all configured keys/models."
            : "{$label} failed after {$attempt} attempt(s): ".$this->truncateError(implode(' | ', array_unique($errors)), 420);

        throw new RuntimeException($summary);
    }

    private function responseContainsInlineImage(Response $response): bool
    {
        $parts = data_get($response->json(), 'candidates.0.content.parts', []);

        if (! is_array($parts)) {
            return false;
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            $data = is_array($inline) ? (string) ($inline['data'] ?? '') : '';

            if ($data !== '') {
                return true;
            }
        }

        return false;
    }

    private function isAuthFailure(int $status, string $body): bool
    {
        if (in_array($status, [401, 403], true)) {
            return true;
        }

        $haystack = strtolower($body);

        return str_contains($haystack, 'api key not valid')
            || str_contains($haystack, 'invalid api key')
            || str_contains($haystack, 'permission denied')
            || str_contains($haystack, 'api_key_invalid');
    }

    private function shouldTryNext(int $status, string $body): bool
    {
        if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
            return true;
        }

        // Model missing / not enabled for this project — try the next model.
        if ($status === 404) {
            return true;
        }

        $haystack = strtolower($body);

        return str_contains($haystack, 'resource_exhausted')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'quota')
            || str_contains($haystack, 'unavailable')
            || str_contains($haystack, 'overloaded')
            || str_contains($haystack, 'model not found')
            || str_contains($haystack, 'is not found');
    }

    /**
     * @param  list<mixed>  $candidates
     * @return list<string>
     */
    private function uniqueModels(array $candidates, string $fallback): array
    {
        $models = [];

        foreach ($candidates as $model) {
            if (! is_string($model)) {
                continue;
            }

            $model = trim($model);

            if ($model === '' || in_array($model, $models, true)) {
                continue;
            }

            $models[] = $model;
        }

        return $models !== [] ? $models : [$fallback];
    }

    private function formatHttpError(string $label, int $status, string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return "{$label} error ({$status}).";
        }

        $decoded = json_decode($body, true);
        $message = is_array($decoded)
            ? (string) (data_get($decoded, 'error.message') ?: data_get($decoded, 'message') ?: '')
            : '';

        if ($message !== '') {
            return "{$label} error ({$status}): ".$this->truncateError($message);
        }

        return "{$label} error ({$status}): ".$this->truncateError($body);
    }

    private function truncateError(string $message, int $max = 280): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (strlen($message) <= $max) {
            return $message;
        }

        return substr($message, 0, $max - 1).'…';
    }
}
