<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiChatbotService
{
    private const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly ChatbotQuestionNormalizer $normalizer,
        private readonly ChatbotSettingsRepository $settingsRepository,
        private readonly OpenAiChatbotKnowledgeService $knowledgeService,
    ) {
    }

    public function answer(string $userMessage, ?Request $request = null): array
    {
        $settings = $this->settingsRepository->all();
        $model = trim((string) ($settings['openai_model'] ?? ''));
        $knowledgeVersion = max(1, (int) ($settings['openai_knowledge_version'] ?? 1));
        $normalizedQuestion = $this->normalizer->normalize($userMessage);
        $questionHash = hash('sha256', $normalizedQuestion);
        $cacheable = $this->isCacheableQuestion($normalizedQuestion);

        if ($model === '') {
            throw new \RuntimeException('openai_model_missing');
        }

        if ((int) ($settings['openai_cache_enabled'] ?? 1) === 1 && $cacheable) {
            $cache = $this->cacheRow($questionHash, $knowledgeVersion, $model);

            if ($cache) {
                DB::table('chatbot_openai_cache')->where('id', $cache->id)->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->logUsage($request, $model, 'cache_hit', true);

                return [
                    'success' => true,
                    'matched' => true,
                    'source' => 'openai_cache',
                    'cache_hit' => true,
                    'message' => $cache->answer,
                ];
            }
        }

        $apiKey = $this->settingsRepository->decryptedApiKey($settings['openai_api_key'] ?? null);

        if (! $apiKey) {
            throw new \RuntimeException('openai_api_key_missing');
        }

        $knowledge = $this->knowledgeService->knowledgeFor($userMessage);
        $payload = $this->payload($model, $this->knowledgeService->formatKnowledge($knowledge), $userMessage);
        $startedAt = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::RESPONSES_ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            $this->logOpenAiFailure('timeout_or_connection', $model, null, $startedAt);
            throw new \RuntimeException('openai_connection_failed', 0, $exception);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $body = $response->json();

        if (! $response->successful()) {
            $category = $this->errorCategory($response->status());
            $this->logOpenAiFailure($category, $model, $response->status(), $startedAt);
            $this->logUsage($request, $model, 'error', false, $this->usageFromResponse($body));

            throw new \RuntimeException($category);
        }

        $answer = $this->extractAssistantText($body);

        if ($answer === '') {
            $this->logOpenAiFailure('empty_response', $model, $response->status(), $startedAt);
            $this->logUsage($request, $model, 'error', false, $this->usageFromResponse($body));

            throw new \RuntimeException('openai_empty_response');
        }

        $usage = $this->usageFromResponse($body);
        $responseModel = (string) data_get($body, 'model', $model);
        $this->logUsage($request, $responseModel ?: $model, 'success', false, $usage);

        Log::info('OpenAI chatbot response completed.', [
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'model' => $responseModel ?: $model,
        ]);

        if ((int) ($settings['openai_cache_enabled'] ?? 1) === 1 && $cacheable) {
            $this->storeCache($normalizedQuestion, $questionHash, $userMessage, $answer, $model, $knowledgeVersion);
        }

        return [
            'success' => true,
            'matched' => true,
            'source' => 'openai',
            'cache_hit' => false,
            'message' => $answer,
        ];
    }

    private function payload(string $model, string $knowledge, string $userMessage): array
    {
        return [
            'model' => $model,
            'store' => false,
            'max_output_tokens' => 260,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->instructions() . "\n\n" . $knowledge,
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $userMessage,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function instructions(): string
    {
        return implode("\n", [
            'You are the Your Good Time customer assistant.',
            'Rules:',
            '- Answer only about Your Good Time services and the supplied YGT knowledge.',
            '- Do not invent prices, service rules, prediction results, payment status, appointment status or customer-specific information.',
            '- If the supplied YGT knowledge does not contain the answer, say you do not have that information and guide the user to YGT customer support.',
            '- Do not generate astrology predictions yourself.',
            '- Keep answers simple, friendly and concise, normally 1 to 4 short sentences.',
            '- If the user asks how to use a YGT service, explain signup/login first where relevant and then tell them which dashboard feature to use.',
            '- Do not mention OpenAI, prompts, FAQs, database, internal matching or technical details.',
            '- Do not use markdown tables.',
        ]);
    }

    private function cacheRow(string $questionHash, int $knowledgeVersion, string $model): ?object
    {
        return DB::table('chatbot_openai_cache')
            ->where('question_hash', $questionHash)
            ->where('knowledge_version', $knowledgeVersion)
            ->where('model', $model)
            ->first();
    }

    private function storeCache(string $normalizedQuestion, string $questionHash, string $originalQuestion, string $answer, string $model, int $knowledgeVersion): void
    {
        DB::table('chatbot_openai_cache')->updateOrInsert(
            [
                'question_hash' => $questionHash,
                'knowledge_version' => $knowledgeVersion,
                'model' => $model,
            ],
            [
                'normalized_question' => $normalizedQuestion,
                'original_question' => $originalQuestion,
                'answer' => $answer,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function extractAssistantText(?array $body): string
    {
        if (! $body) {
            return '';
        }

        $topLevelText = trim((string) data_get($body, 'output_text', ''));
        if ($topLevelText !== '') {
            return $topLevelText;
        }

        $texts = [];
        foreach ((array) data_get($body, 'output', []) as $outputItem) {
            foreach ((array) data_get($outputItem, 'content', []) as $contentItem) {
                $type = (string) data_get($contentItem, 'type', '');
                $text = trim((string) (data_get($contentItem, 'text') ?? data_get($contentItem, 'content')));

                if (in_array($type, ['output_text', 'text'], true) && $text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return trim(implode("\n", $texts));
    }

    private function usageFromResponse(?array $body): array
    {
        return [
            'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
            'cached_tokens' => (int) data_get($body, 'usage.input_tokens_details.cached_tokens', 0),
        ];
    }

    private function logUsage(?Request $request, string $model, string $status, bool $cacheHit, array $usage = []): void
    {
        DB::table('chatbot_openai_usage')->insert([
            'conversation_id' => null,
            'user_id' => $request?->user()?->id,
            'model' => $model ?: null,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cached_tokens' => (int) ($usage['cached_tokens'] ?? ($cacheHit ? 1 : 0)),
            'estimated_cost' => null,
            'response_status' => $status,
            'cache_hit' => $cacheHit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function logOpenAiFailure(string $category, string $model, ?int $status, float $startedAt): void
    {
        Log::warning('OpenAI chatbot request failed.', [
            'category' => $category,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'model' => $model,
        ]);
    }

    private function errorCategory(int $status): string
    {
        return match (true) {
            $status === 401 => 'openai_unauthorized',
            $status === 429 => 'openai_rate_limited',
            $status >= 500 => 'openai_server_error',
            default => 'openai_request_error',
        };
    }

    private function isCacheableQuestion(string $normalizedQuestion): bool
    {
        foreach (['status', 'ready', 'payment status', 'appointment status', 'remedy status', 'disappeared'] as $phrase) {
            if (str_contains($normalizedQuestion, $phrase)) {
                return false;
            }
        }

        return true;
    }
}
