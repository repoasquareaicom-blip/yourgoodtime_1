<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ChatbotSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminChatbotController extends Controller
{
    public function __construct(private readonly ChatbotSettingsRepository $settingsRepository)
    {
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settingsRepository->publicAdminPayload(),
            'cache_count' => DB::table('chatbot_openai_cache')->count(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chatbot_model' => ['required', Rule::in(['ygt', 'openai'])],
            'openai_model' => ['nullable', 'string', 'max:100'],
            'openai_api_key' => ['nullable', 'string', 'max:500'],
            'openai_fallback_ygt' => ['required', 'boolean'],
            'openai_cache_enabled' => ['required', 'boolean'],
            'openai_knowledge_version' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $this->settingsRepository->save([
            'chatbot_model' => $validated['chatbot_model'],
            'openai_model' => $validated['openai_model'] ?? '',
            'openai_fallback_ygt' => (int) $validated['openai_fallback_ygt'],
            'openai_cache_enabled' => (int) $validated['openai_cache_enabled'],
            'openai_knowledge_version' => (int) $validated['openai_knowledge_version'],
            'openai_api_key' => $validated['openai_api_key'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chatbot settings saved.',
            'data' => $this->settingsRepository->publicAdminPayload(),
            'cache_count' => DB::table('chatbot_openai_cache')->count(),
        ]);
    }

    public function clearOpenAiCache(): JsonResponse
    {
        $deleted = DB::table('chatbot_openai_cache')->delete();

        return response()->json([
            'success' => true,
            'message' => 'OpenAI chatbot cache cleared.',
            'deleted' => $deleted,
        ]);
    }

    public function faqs(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->faqRows(),
            'topics' => $this->topicRows(false),
            'intents' => $this->intentRows(false),
        ]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $validated = $request->validate($this->faqRules());

        $faq = DB::transaction(function () use ($validated): object {
            $faqId = DB::table('chatbot_faqs')->insertGetId([
                'topic_id' => $validated['topic_id'],
                'intent_id' => $validated['intent_id'],
                'question' => $validated['question'],
                'answer' => $validated['answer'],
                'priority' => $validated['priority'] ?? 0,
                'language' => $validated['language'] ?? 'en',
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_faq_keywords', 'faq_id', $faqId, $validated['keywords'] ?? []);

            return $this->faqById($faqId);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot FAQ created.',
            'data' => $faq,
        ], 201);
    }

    public function updateFaq(Request $request, int $id): JsonResponse
    {
        $this->ensureExists('chatbot_faqs', $id);
        $validated = $request->validate($this->faqRules());

        $faq = DB::transaction(function () use ($id, $validated): object {
            DB::table('chatbot_faqs')->where('id', $id)->update([
                'topic_id' => $validated['topic_id'],
                'intent_id' => $validated['intent_id'],
                'question' => $validated['question'],
                'answer' => $validated['answer'],
                'priority' => $validated['priority'] ?? 0,
                'language' => $validated['language'] ?? 'en',
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_faq_keywords', 'faq_id', $id, $validated['keywords'] ?? []);

            return $this->faqById($id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot FAQ updated.',
            'data' => $faq,
        ]);
    }

    public function destroyFaq(int $id): JsonResponse
    {
        $this->ensureExists('chatbot_faqs', $id);

        DB::table('chatbot_faqs')->where('id', $id)->update(['status' => 0]);

        return response()->json([
            'success' => true,
            'message' => 'Chatbot FAQ deactivated.',
        ]);
    }

    public function topics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->topicRows(),
        ]);
    }

    public function storeTopic(Request $request): JsonResponse
    {
        $validated = $request->validate($this->topicRules());

        $topic = DB::transaction(function () use ($validated): object {
            $topicId = DB::table('chatbot_topics')->insertGetId([
                'topic_code' => $validated['topic_code'],
                'topic_name' => $validated['topic_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_topic_keywords', 'topic_id', $topicId, $validated['keywords'] ?? []);

            return $this->topicById($topicId);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot topic created.',
            'data' => $topic,
        ], 201);
    }

    public function updateTopic(Request $request, int $id): JsonResponse
    {
        $this->ensureExists('chatbot_topics', $id);
        $validated = $request->validate($this->topicRules($id));

        $topic = DB::transaction(function () use ($id, $validated): object {
            DB::table('chatbot_topics')->where('id', $id)->update([
                'topic_code' => $validated['topic_code'],
                'topic_name' => $validated['topic_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_topic_keywords', 'topic_id', $id, $validated['keywords'] ?? []);

            return $this->topicById($id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot topic updated.',
            'data' => $topic,
        ]);
    }

    public function destroyTopic(int $id): JsonResponse
    {
        $this->ensureExists('chatbot_topics', $id);

        DB::table('chatbot_topics')->where('id', $id)->update(['status' => 0]);

        return response()->json([
            'success' => true,
            'message' => 'Chatbot topic deactivated.',
        ]);
    }

    public function intents(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->intentRows(),
        ]);
    }

    public function storeIntent(Request $request): JsonResponse
    {
        $validated = $request->validate($this->intentRules());

        $intent = DB::transaction(function () use ($validated): object {
            $intentId = DB::table('chatbot_intents')->insertGetId([
                'intent_code' => $validated['intent_code'],
                'intent_name' => $validated['intent_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_intent_keywords', 'intent_id', $intentId, $validated['keywords'] ?? []);

            return $this->intentById($intentId);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot intent created.',
            'data' => $intent,
        ], 201);
    }

    public function updateIntent(Request $request, int $id): JsonResponse
    {
        $this->ensureExists('chatbot_intents', $id);
        $validated = $request->validate($this->intentRules($id));

        $intent = DB::transaction(function () use ($id, $validated): object {
            DB::table('chatbot_intents')->where('id', $id)->update([
                'intent_code' => $validated['intent_code'],
                'intent_name' => $validated['intent_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 1,
            ]);

            $this->syncKeywords('chatbot_intent_keywords', 'intent_id', $id, $validated['keywords'] ?? []);

            return $this->intentById($id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Chatbot intent updated.',
            'data' => $intent,
        ]);
    }

    public function destroyIntent(int $id): JsonResponse
    {
        $this->ensureExists('chatbot_intents', $id);

        DB::table('chatbot_intents')->where('id', $id)->update(['status' => 0]);

        return response()->json([
            'success' => true,
            'message' => 'Chatbot intent deactivated.',
        ]);
    }

    private function faqRules(): array
    {
        return [
            'topic_id' => ['required', 'integer', 'exists:chatbot_topics,id'],
            'intent_id' => ['required', 'integer', 'exists:chatbot_intents,id'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:20000'],
            'priority' => ['required', 'integer', 'min:0', 'max:100000'],
            'language' => ['required', 'string', 'max:10'],
            'status' => ['required', 'boolean'],
            'keywords' => ['array'],
            'keywords.*.id' => ['nullable', 'integer', 'exists:chatbot_faq_keywords,id'],
            'keywords.*.keyword' => ['nullable', 'string', 'max:255'],
            'keywords.*.weight' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'keywords.*.language' => ['nullable', 'string', 'max:10'],
            'keywords.*.status' => ['nullable', 'boolean'],
            'keywords.*._delete' => ['nullable', 'boolean'],
        ];
    }

    private function topicRules(?int $id = null): array
    {
        return [
            'topic_code' => ['required', 'string', 'max:100', Rule::unique('chatbot_topics', 'topic_code')->ignore($id)],
            'topic_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'boolean'],
            'keywords' => ['array'],
            'keywords.*.id' => ['nullable', 'integer', 'exists:chatbot_topic_keywords,id'],
            'keywords.*.keyword' => ['nullable', 'string', 'max:255'],
            'keywords.*.weight' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'keywords.*.language' => ['nullable', 'string', 'max:10'],
            'keywords.*.status' => ['nullable', 'boolean'],
            'keywords.*._delete' => ['nullable', 'boolean'],
        ];
    }

    private function intentRules(?int $id = null): array
    {
        return [
            'intent_code' => ['required', 'string', 'max:100', Rule::unique('chatbot_intents', 'intent_code')->ignore($id)],
            'intent_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'boolean'],
            'keywords' => ['array'],
            'keywords.*.id' => ['nullable', 'integer', 'exists:chatbot_intent_keywords,id'],
            'keywords.*.keyword' => ['nullable', 'string', 'max:255'],
            'keywords.*.weight' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'keywords.*.language' => ['nullable', 'string', 'max:10'],
            'keywords.*.status' => ['nullable', 'boolean'],
            'keywords.*._delete' => ['nullable', 'boolean'],
        ];
    }

    private function syncKeywords(string $table, string $parentColumn, int $parentId, array $keywords): void
    {
        $seen = [];

        foreach ($keywords as $keyword) {
            $keywordId = ! empty($keyword['id']) ? (int) $keyword['id'] : null;

            if (! empty($keyword['_delete'])) {
                if ($keywordId) {
                    DB::table($table)
                        ->where('id', $keywordId)
                        ->where($parentColumn, $parentId)
                        ->update(['status' => 0]);
                }
                continue;
            }

            $text = trim((string) ($keyword['keyword'] ?? ''));
            if ($text === '') {
                continue;
            }

            $language = strtolower(trim((string) ($keyword['language'] ?? 'en'))) ?: 'en';
            $duplicateKey = strtolower($language.'|'.$text);
            if (isset($seen[$duplicateKey])) {
                continue;
            }
            $seen[$duplicateKey] = true;

            $data = [
                'keyword' => $text,
                'weight' => (int) ($keyword['weight'] ?? 5),
                'language' => $language,
                'status' => (int) ($keyword['status'] ?? 1),
            ];

            if ($keywordId) {
                DB::table($table)
                    ->where('id', $keywordId)
                    ->where($parentColumn, $parentId)
                    ->update($data);
                continue;
            }

            $existingId = DB::table($table)
                ->where($parentColumn, $parentId)
                ->where('keyword', $text)
                ->where('language', $language)
                ->value('id');

            if ($existingId) {
                DB::table($table)->where('id', $existingId)->update($data);
            } else {
                DB::table($table)->insert([$parentColumn => $parentId, ...$data]);
            }
        }
    }

    private function faqRows(): array
    {
        $faqs = DB::table('chatbot_faqs as f')
            ->leftJoin('chatbot_topics as t', 't.id', '=', 'f.topic_id')
            ->leftJoin('chatbot_intents as i', 'i.id', '=', 'f.intent_id')
            ->select('f.*', 't.topic_code', 't.topic_name', 'i.intent_code', 'i.intent_name')
            ->orderByDesc('f.status')
            ->orderByDesc('f.priority')
            ->orderBy('f.id')
            ->get();

        $keywords = $this->keywordsByParent('chatbot_faq_keywords', 'faq_id', $faqs->pluck('id'));

        return $faqs->map(fn (object $faq): array => [
            'id' => $faq->id,
            'topic_id' => $faq->topic_id,
            'intent_id' => $faq->intent_id,
            'topic_code' => $faq->topic_code,
            'topic_name' => $faq->topic_name,
            'intent_code' => $faq->intent_code,
            'intent_name' => $faq->intent_name,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'priority' => $faq->priority,
            'language' => $faq->language,
            'status' => (int) $faq->status,
            'keywords' => $keywords->get($faq->id, collect())->values(),
        ])->values()->all();
    }

    private function topicRows(bool $includeKeywords = true): array
    {
        $topics = DB::table('chatbot_topics')
            ->orderByDesc('status')
            ->orderBy('topic_code')
            ->get();
        $keywords = $includeKeywords
            ? $this->keywordsByParent('chatbot_topic_keywords', 'topic_id', $topics->pluck('id'))
            : collect();

        return $topics->map(fn (object $topic): array => [
            'id' => $topic->id,
            'topic_code' => $topic->topic_code,
            'topic_name' => $topic->topic_name,
            'description' => $topic->description,
            'status' => (int) $topic->status,
            'keywords' => $keywords->get($topic->id, collect())->values(),
        ])->values()->all();
    }

    private function intentRows(bool $includeKeywords = true): array
    {
        $intents = DB::table('chatbot_intents')
            ->orderByDesc('status')
            ->orderBy('intent_code')
            ->get();
        $keywords = $includeKeywords
            ? $this->keywordsByParent('chatbot_intent_keywords', 'intent_id', $intents->pluck('id'))
            : collect();

        return $intents->map(fn (object $intent): array => [
            'id' => $intent->id,
            'intent_code' => $intent->intent_code,
            'intent_name' => $intent->intent_name,
            'description' => $intent->description,
            'status' => (int) $intent->status,
            'keywords' => $keywords->get($intent->id, collect())->values(),
        ])->values()->all();
    }

    private function faqById(int $id): object
    {
        return (object) collect($this->faqRows())->firstWhere('id', $id);
    }

    private function topicById(int $id): object
    {
        return (object) collect($this->topicRows())->firstWhere('id', $id);
    }

    private function intentById(int $id): object
    {
        return (object) collect($this->intentRows())->firstWhere('id', $id);
    }

    private function keywordsByParent(string $table, string $parentColumn, Collection $parentIds): Collection
    {
        if ($parentIds->isEmpty()) {
            return collect();
        }

        return DB::table($table)
            ->whereIn($parentColumn, $parentIds)
            ->orderByDesc('status')
            ->orderByDesc('weight')
            ->orderBy('keyword')
            ->get(['id', $parentColumn, 'keyword', 'weight', 'language', 'status'])
            ->map(function (object $keyword) use ($parentColumn): array {
                return [
                    'id' => $keyword->id,
                    $parentColumn => $keyword->{$parentColumn},
                    'keyword' => $keyword->keyword,
                    'weight' => $keyword->weight,
                    'language' => $keyword->language,
                    'status' => (int) $keyword->status,
                ];
            })
            ->groupBy($parentColumn);
    }

    private function ensureExists(string $table, int $id): void
    {
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
    }
}
