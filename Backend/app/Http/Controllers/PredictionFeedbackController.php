<?php

namespace App\Http\Controllers;

use App\Models\PredictionFeedback;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PredictionFeedbackController extends Controller
{
    public function store(Request $request, PredictionRequest $predictionRequest): JsonResponse
    {
        $validated = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'prediction_result_id' => ['required', 'integer', 'exists:prediction_results,id'],
            'prediction_type' => ['required', 'string', Rule::in(['past', 'future'])],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'accuracy_band' => ['required', 'string', Rule::in([
                'BELOW_50',
                'FROM_50_TO_74',
                'FROM_75_TO_89',
                'ABOVE_OR_EQUAL_90',
            ])],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! hash_equals($predictionRequest->request_uuid, $validated['request_uuid'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to submit feedback for this prediction.',
            ], 403);
        }

        $result = PredictionResult::query()
            ->whereKey($validated['prediction_result_id'])
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $validated['prediction_type'])
            ->where('status', 'completed')
            ->first();

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Prediction result not found.',
            ], 404);
        }

        $feedback = PredictionFeedback::query()->updateOrCreate(
            [
                'prediction_result_id' => $result->id,
                'prediction_type' => $validated['prediction_type'],
            ],
            [
                'prediction_request_id' => $predictionRequest->id,
                'rating' => $validated['rating'],
                'accuracy_band' => $validated['accuracy_band'],
                'comments' => $validated['comments'] ?? null,
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully.',
            'data' => [
                'id' => $feedback->id,
                'submitted_at' => $feedback->submitted_at?->toJSON(),
            ],
        ]);
    }
}
