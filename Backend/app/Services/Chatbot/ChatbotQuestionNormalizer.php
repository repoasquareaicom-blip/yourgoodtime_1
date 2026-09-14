<?php

namespace App\Services\Chatbot;

class ChatbotQuestionNormalizer
{
    public function normalize(string $message): string
    {
        $message = mb_strtolower(trim($message), 'UTF-8');
        $message = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $message);

        return trim(preg_replace('/\s+/u', ' ', $message));
    }

    public function hash(string $message): string
    {
        return hash('sha256', $this->normalize($message));
    }
}
