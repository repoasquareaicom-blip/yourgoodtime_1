<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpenAiChatbotKnowledgeService
{
    public function __construct(private readonly ChatbotQuestionNormalizer $normalizer)
    {
    }

    public function knowledgeFor(string $userMessage): array
    {
        $message = $this->normalizer->normalize($userMessage);
        $topicMatches = $this->matchedTopics($message);
        $intentMatches = $this->matchedIntents($message);
        $topicIds = $topicMatches->pluck('topic_id')->unique()->values();
        $intentIds = $intentMatches->pluck('intent_id')->unique()->values();

        if ($topicIds->isNotEmpty() && $intentIds->isNotEmpty()) {
            $faqs = DB::table('chatbot_faqs as f')
                ->leftJoin('chatbot_topics as t', 't.id', '=', 'f.topic_id')
                ->leftJoin('chatbot_intents as i', 'i.id', '=', 'f.intent_id')
                ->where('f.status', 1)
                ->whereIn('f.topic_id', $topicIds)
                ->whereIn('f.intent_id', $intentIds)
                ->orderByDesc('f.priority')
                ->limit(8)
                ->get(['f.question', 'f.answer', 't.topic_code', 'i.intent_code']);

            if ($faqs->isNotEmpty()) {
                return [
                    'mode' => 'matched',
                    'faqs' => $faqs,
                    'topics' => $topicMatches->pluck('topic_code')->unique()->values()->all(),
                    'intents' => $intentMatches->pluck('intent_code')->unique()->values()->all(),
                ];
            }
        }

        return [
            'mode' => 'general',
            'faqs' => $this->generalFaqs(),
            'topics' => [],
            'intents' => [],
        ];
    }

    public function formatKnowledge(array $knowledge): string
    {
        $lines = [
            'YGT approved knowledge:',
            'Core rules:',
            '- Your Good Time provides Past Prediction, Future Prediction, Temple Remedy and Talk to Astrologer services.',
            '- One account is for one person\'s prediction data.',
            '- YGT services are non-refundable.',
            '- Do not claim live prediction, payment, remedy or appointment status unless live status data is supplied.',
            '- Past and Future Prediction videos are available in Tamil, English and Hindi.',
            '- Tamil is the original recording; English and Hindi versions are AI-dubbed and may have minor voice or lip-sync differences.',
            '- Temple Remedy is available in Tamil, English and Hindi.',
            '- Talk to Astrologer appointments are currently available in Tamil and English only.',
            '',
            'Relevant FAQs:',
        ];

        foreach ($knowledge['faqs'] as $faq) {
            $lines[] = '- Q: ' . $this->singleLine($faq->question);
            $lines[] = '  A: ' . $this->singleLine($faq->answer);
        }

        return implode("\n", $lines);
    }

    private function matchedTopics(string $message): Collection
    {
        return DB::table('chatbot_topic_keywords as k')
            ->join('chatbot_topics as t', 't.id', '=', 'k.topic_id')
            ->where('k.status', 1)
            ->where('t.status', 1)
            ->select('k.topic_id', 'k.keyword', 'k.weight', 't.topic_code')
            ->get()
            ->filter(fn (object $row): bool => $this->keywordMatches($message, $row->keyword))
            ->groupBy('topic_id')
            ->map(function (Collection $group): object {
                $match = clone $group->first();
                $match->score = $group->sum('weight');

                return $match;
            })
            ->sortByDesc('score')
            ->values();
    }

    private function matchedIntents(string $message): Collection
    {
        return DB::table('chatbot_intent_keywords as k')
            ->join('chatbot_intents as i', 'i.id', '=', 'k.intent_id')
            ->where('k.status', 1)
            ->where('i.status', 1)
            ->select('k.intent_id', 'k.keyword', 'k.weight', 'i.intent_code')
            ->get()
            ->filter(fn (object $row): bool => $this->keywordMatches($message, $row->keyword))
            ->groupBy('intent_id')
            ->map(function (Collection $group): object {
                $match = clone $group->first();
                $match->score = $group->sum('weight');

                return $match;
            })
            ->sortByDesc('score')
            ->values();
    }

    private function generalFaqs(): Collection
    {
        return DB::table('chatbot_faqs as f')
            ->leftJoin('chatbot_topics as t', 't.id', '=', 'f.topic_id')
            ->leftJoin('chatbot_intents as i', 'i.id', '=', 'f.intent_id')
            ->where('f.status', 1)
            ->whereIn('t.topic_code', ['general', 'login', 'past_prediction', 'future_prediction', 'parikaaram', 'appointment'])
            ->orderByDesc('f.priority')
            ->limit(10)
            ->get(['f.question', 'f.answer', 't.topic_code', 'i.intent_code']);
    }

    private function keywordMatches(string $message, string $keyword): bool
    {
        $keyword = $this->normalizer->normalize($keyword);

        if ($keyword === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $message) === 1;
    }

    private function singleLine(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
