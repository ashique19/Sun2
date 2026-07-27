<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiClient
{
    public function isConfigured(): bool
    {
        return (bool) config('gemini.api_key');
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

        $model = config('gemini.model', 'gemini-2.0-flash');
        $url = config('gemini.base_url').'/models/'.$model.':generateContent';

        $response = Http::timeout(config('gemini.timeout', 20))
            ->withQueryParameters(['key' => config('gemini.api_key')])
            ->acceptJson()
            ->asJson()
            ->post($url, [
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
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini API error ('.$response->status().'): '.$response->body());
        }

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

        $model = config('gemini.image_model', 'gemini-2.5-flash-image');
        $url = config('gemini.base_url').'/models/'.$model.':generateContent';

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

        $response = Http::timeout(config('gemini.image_timeout', 90))
            ->withQueryParameters(['key' => config('gemini.api_key')])
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Gemini image API error ('.$response->status().'): '.$response->body());
        }

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
}
