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
}
