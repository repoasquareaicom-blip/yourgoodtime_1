<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\DB;

class YgtChatbotService
{
    public function __construct(private readonly ChatbotQuestionNormalizer $normalizer)
    {
    }

    public function answer(string $userMessage): array
    {
        $message = $this->normalizer->normalize($userMessage);

        $topicMatches = DB::table('chatbot_topic_keywords as k')
            ->join('chatbot_topics as t', 't.id', '=', 'k.topic_id')
            ->where('k.status', 1)
            ->where('t.status', 1)
            ->select('k.topic_id', 'k.keyword', 'k.weight', 't.topic_code', 't.topic_name')
            ->get()
            ->filter(fn (object $row): bool => $this->keywordMatches($message, $row->keyword));

        $topic = $this->selectBestMatch($topicMatches, 'topic_id');

        $intentMatches = DB::table('chatbot_intent_keywords as k')
            ->join('chatbot_intents as i', 'i.id', '=', 'k.intent_id')
            ->where('k.status', 1)
            ->where('i.status', 1)
            ->select('k.intent_id', 'k.keyword', 'k.weight', 'i.intent_code', 'i.intent_name')
            ->get()
            ->filter(fn (object $row): bool => $this->keywordMatches($message, $row->keyword));

        $intent = $this->selectBestMatch($intentMatches, 'intent_id');

        if ($topic && $intent) {
            $topic = $this->selectBestTopicWithFaqEvidence($topicMatches, $topic, $intent, $message);
        }

        if (! $topic || ! $intent) {
            return [
                'success' => true,
                'matched' => false,
                'message' => "I'm sorry, I couldn't understand your question. Please ask about Past Prediction, Future Prediction, Temple Remedy, Talk to Astrologer, payment, login or birth details.",
            ];
        }

        $faqCandidates = $this->faqCandidates($topic->topic_id, $intent->intent_id);
        $faq = $this->selectBestFaq($faqCandidates, $message);

        if (! $faq) {
            return [
                'success' => true,
                'matched' => false,
                'topic' => $topic->topic_code,
                'intent' => $intent->intent_code,
                'message' => "I understood your question, but I don't have an approved answer for it yet.",
            ];
        }

        return [
            'success' => true,
            'matched' => true,
            'topic' => $topic->topic_code,
            'intent' => $intent->intent_code,
            'question' => $faq->question,
            'message' => $faq->answer,
        ];
    }

    private function keywordMatches(string $message, string $keyword): bool
    {
        $keyword = $this->normalizer->normalize($keyword);

        if ($keyword === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $message) === 1;
    }

    private function selectBestMatch($matches, string $idColumn): ?object
    {
        if ($matches->isEmpty()) {
            return null;
        }

        return $matches
            ->groupBy($idColumn)
            ->map(function ($group) {
                $match = clone $group->first();
                $match->score = $group->sum('weight');
                $match->matched_keywords = $group->count();

                return $match;
            })
            ->sort(function ($left, $right): int {
                if ($left->score === $right->score) {
                    if ($left->matched_keywords === $right->matched_keywords) {
                        return $right->weight <=> $left->weight;
                    }

                    return $right->matched_keywords <=> $left->matched_keywords;
                }

                return $right->score <=> $left->score;
            })
            ->first();
    }

    private function selectBestFaq($faqCandidates, string $message): ?object
    {
        if ($faqCandidates->isEmpty()) {
            return null;
        }

        $faqKeywordScores = $this->faqKeywordScores($faqCandidates, $message);

        if ($faqKeywordScores->isEmpty()) {
            return $faqCandidates->first();
        }

        return $faqCandidates
            ->sort(function ($left, $right) use ($faqKeywordScores): int {
                $leftScore = $faqKeywordScores->get($left->id, ['matched_keywords' => 0, 'score' => 0]);
                $rightScore = $faqKeywordScores->get($right->id, ['matched_keywords' => 0, 'score' => 0]);

                if ($leftScore['matched_keywords'] === $rightScore['matched_keywords']) {
                    if ($leftScore['score'] === $rightScore['score']) {
                        return $right->priority <=> $left->priority;
                    }

                    return $rightScore['score'] <=> $leftScore['score'];
                }

                return $rightScore['matched_keywords'] <=> $leftScore['matched_keywords'];
            })
            ->first();
    }

    private function selectBestTopicWithFaqEvidence($topicMatches, object $selectedTopic, object $intent, string $message): object
    {
        if ($topicMatches->isEmpty()) {
            return $selectedTopic;
        }

        $rankedTopics = $topicMatches
            ->groupBy('topic_id')
            ->map(function ($group) use ($intent, $message) {
                $topic = clone $group->first();
                $topic->score = $group->sum('weight');
                $topic->matched_keywords = $group->count();

                $faqCandidates = $this->faqCandidates($topic->topic_id, $intent->intent_id);
                $faqKeywordScores = $this->faqKeywordScores($faqCandidates, $message);
                $bestFaqScore = $faqKeywordScores
                    ->sort(function ($left, $right): int {
                        if ($left['matched_keywords'] === $right['matched_keywords']) {
                            return $right['score'] <=> $left['score'];
                        }

                        return $right['matched_keywords'] <=> $left['matched_keywords'];
                    })
                    ->first() ?? ['matched_keywords' => 0, 'score' => 0];

                $topic->faq_matched_keywords = $bestFaqScore['matched_keywords'];
                $topic->faq_score = $bestFaqScore['score'];

                return $topic;
            });

        $currentTopic = $rankedTopics->get($selectedTopic->topic_id);
        $bestTopicByFaqEvidence = $rankedTopics
            ->sort(function ($left, $right): int {
                if ($left->faq_matched_keywords === $right->faq_matched_keywords) {
                    if ($left->faq_score === $right->faq_score) {
                        if ($left->score === $right->score) {
                            return $right->matched_keywords <=> $left->matched_keywords;
                        }

                        return $right->score <=> $left->score;
                    }

                    return $right->faq_score <=> $left->faq_score;
                }

                return $right->faq_matched_keywords <=> $left->faq_matched_keywords;
            })
            ->first();

        if ($bestTopicByFaqEvidence
            && $bestTopicByFaqEvidence->faq_matched_keywords > 0
            && (! $currentTopic || $currentTopic->faq_matched_keywords === 0)) {
            return $bestTopicByFaqEvidence;
        }

        return $selectedTopic;
    }

    private function faqCandidates(int $topicId, int $intentId)
    {
        return DB::table('chatbot_faqs')
            ->where('topic_id', $topicId)
            ->where('intent_id', $intentId)
            ->where('status', 1)
            ->orderByDesc('priority')
            ->get();
    }

    private function faqKeywordScores($faqCandidates, string $message)
    {
        if ($faqCandidates->isEmpty()) {
            return collect();
        }

        return DB::table('chatbot_faq_keywords')
            ->whereIn('faq_id', $faqCandidates->pluck('id'))
            ->where('status', 1)
            ->select('faq_id', 'keyword', 'weight')
            ->get()
            ->filter(fn (object $row): bool => $this->keywordMatches($message, $row->keyword))
            ->groupBy('faq_id')
            ->map(fn ($matches): array => [
                'matched_keywords' => $matches->count(),
                'score' => $matches->sum('weight'),
            ]);
    }
}
