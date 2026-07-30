<?php

namespace Tests\Feature;

use App\Services\Admin\GeminiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeminiClientFailoverTest extends TestCase
{
    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    #[Test]
    public function is_configured_when_any_api_key_is_present(): void
    {
        config([
            'gemini.api_key' => null,
            'gemini.api_keys' => ['secondary-key'],
        ]);

        $this->assertTrue(app(GeminiClient::class)->isConfigured());
        $this->assertSame(['secondary-key'], app(GeminiClient::class)->apiKeys());
    }

    #[Test]
    public function default_config_includes_recommended_text_and_image_failover_models(): void
    {
        $source = (string) file_get_contents(config_path('gemini.php'));

        foreach ([
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-3-flash-preview',
            'gemini-2.0-flash',
            'gemini-2.5-flash-image',
            'gemini-3.1-flash-image',
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image',
            'gemini-3-pro-image-preview',
        ] as $model) {
            $this->assertStringContainsString($model, $source);
        }

        config([
            'gemini.model' => 'gemini-2.5-flash',
            'gemini.models' => [
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
                'gemini-3.1-flash-lite',
                'gemini-3-flash-preview',
                'gemini-2.0-flash',
            ],
            'gemini.image_model' => 'gemini-2.5-flash-image',
            'gemini.image_models' => [
                'gemini-2.5-flash-image',
                'gemini-3.1-flash-image',
                'gemini-3.1-flash-image-preview',
                'gemini-3-pro-image',
                'gemini-3-pro-image-preview',
            ],
        ]);

        $client = app(GeminiClient::class);

        $this->assertSame([
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-3-flash-preview',
            'gemini-2.0-flash',
        ], $client->textModels());

        $this->assertSame([
            'gemini-2.5-flash-image',
            'gemini-3.1-flash-image',
            'gemini-3.1-flash-image-preview',
            'gemini-3-pro-image',
            'gemini-3-pro-image-preview',
        ], $client->imageModels());
    }

    #[Test]
    public function generate_image_falls_over_to_next_model_on_rate_limit(): void
    {
        config([
            'gemini.api_key' => 'key-1',
            'gemini.api_keys' => [],
            'gemini.image_model' => 'model-a',
            'gemini.image_models' => ['model-a', 'model-b'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        Http::fake([
            'https://example.test/v1beta/models/model-a:generateContent*' => Http::response([
                'error' => ['message' => 'Resource exhausted'],
            ], 429),
            'https://example.test/v1beta/models/model-b:generateContent*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => $this->tinyPngBase64(),
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GeminiClient::class)->generateImage([
            ['text' => 'Make it nicer'],
        ]);

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame($this->tinyPngBase64(), $result['base64']);

        Http::assertSentCount(2);
    }

    #[Test]
    public function generate_image_falls_over_to_next_api_key_on_auth_failure(): void
    {
        config([
            'gemini.api_key' => 'bad-key',
            'gemini.api_keys' => ['good-key'],
            'gemini.image_model' => 'model-a',
            'gemini.image_models' => ['model-a', 'model-b'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $apiKey = $query['key'] ?? '';

            if ($apiKey === 'bad-key') {
                return Http::response(['error' => ['message' => 'API key not valid']], 403);
            }

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => $this->tinyPngBase64(),
                            ],
                        ]],
                    ],
                ]],
            ], 200);
        });

        $result = app(GeminiClient::class)->generateImage([
            ['text' => 'Make it nicer'],
        ]);

        $this->assertSame($this->tinyPngBase64(), $result['base64']);

        // bad key + model-a only (auth skips model-b for that key), then good key + model-a
        Http::assertSentCount(2);
    }

    #[Test]
    public function generate_json_falls_over_across_keys_and_models(): void
    {
        config([
            'gemini.api_key' => 'key-1',
            'gemini.api_keys' => ['key-2'],
            'gemini.model' => 'text-a',
            'gemini.models' => ['text-a', 'text-b'],
            'gemini.base_url' => 'https://example.test/v1beta',
            'gemini.timeout' => 10,
        ]);

        $calls = [];

        Http::fake(function (Request $request) use (&$calls) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $apiKey = $query['key'] ?? '';
            $model = str_contains($request->url(), '/models/text-a:') ? 'text-a' : 'text-b';
            $calls[] = $apiKey.'|'.$model;

            if ($apiKey === 'key-1') {
                return Http::response(['error' => ['message' => 'rate limit exceeded']], 429);
            }

            if ($model === 'text-a') {
                return Http::response(['error' => ['message' => 'model overloaded']], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode(['ok' => true], JSON_THROW_ON_ERROR),
                        ]],
                    ],
                ]],
            ], 200);
        });

        $result = app(GeminiClient::class)->generateJson('sys', 'user');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame([
            'key-1|text-a',
            'key-1|text-b',
            'key-2|text-a',
            'key-2|text-b',
        ], $calls);
    }

    #[Test]
    public function generate_image_aggregates_errors_when_all_attempts_fail(): void
    {
        config([
            'gemini.api_key' => 'key-1',
            'gemini.api_keys' => ['key-2'],
            'gemini.image_model' => 'model-a',
            'gemini.image_models' => ['model-a'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        Http::fake([
            'https://example.test/v1beta/models/model-a:generateContent*' => Http::response([
                'error' => ['message' => 'Resource exhausted'],
            ], 429),
        ]);

        try {
            app(GeminiClient::class)->generateImage([['text' => 'x']]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Gemini image quota exceeded', $e->getMessage());
            $this->assertStringContainsString('GEMINI_API_KEYS', $e->getMessage());
        }
    }

    #[Test]
    public function generate_image_skips_remaining_models_on_billing_quota_for_a_key(): void
    {
        config([
            'gemini.api_key' => 'key-1',
            'gemini.api_keys' => ['key-2'],
            'gemini.image_model' => 'model-a',
            'gemini.image_models' => ['model-a', 'model-b'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        $calls = [];

        Http::fake(function (Request $request) use (&$calls) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $apiKey = $query['key'] ?? '';
            $model = str_contains($request->url(), '/models/model-a:') ? 'model-a' : 'model-b';
            $calls[] = $apiKey.'|'.$model;

            if ($apiKey === 'key-1') {
                return Http::response([
                    'error' => [
                        'message' => 'You exceeded your current quota, please check your plan and billing details.',
                    ],
                ], 429);
            }

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => $this->tinyPngBase64(),
                            ],
                        ]],
                    ],
                ]],
            ], 200);
        });

        $result = app(GeminiClient::class)->generateImage([['text' => 'x']]);

        $this->assertSame($this->tinyPngBase64(), $result['base64']);
        // key-1 model-a only (billing quota skips model-b), then key-2 model-a succeeds
        $this->assertSame(['key-1|model-a', 'key-2|model-a'], $calls);
    }
}
