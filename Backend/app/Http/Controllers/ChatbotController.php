<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ChatbotDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(private readonly ChatbotDispatcher $chatbotDispatcher)
    {
    }

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(
            $this->chatbotDispatcher->answer($validated['message'], $request)
        );
    }
}
