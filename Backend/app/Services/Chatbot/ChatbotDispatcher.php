<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatbotDispatcher
{
    public function __construct(
        private readonly ChatbotSettingsRepository $settingsRepository,
        private readonly YgtChatbotService $ygtChatbotService,
        private readonly OpenAiChatbotService $openAiChatbotService,
    ) {
    }

    public function answer(string $message, Request $request): array
    {
        $settings = $this->settingsRepository->all();

        if (($settings['chatbot_model'] ?? 'ygt') !== 'openai') {
            return $this->ygtChatbotService->answer($message);
        }

        try {
            return $this->openAiChatbotService->answer($message, $request);
        } catch (\Throwable $exception) {
            Log::warning('OpenAI chatbot mode failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ((int) ($settings['openai_fallback_ygt'] ?? 1) === 1) {
                return $this->ygtChatbotService->answer($message);
            }

            return [
                'success' => true,
                'matched' => false,
                'message' => 'The assistant is temporarily unavailable. Please try again in a moment.',
            ];
        }
    }
}
