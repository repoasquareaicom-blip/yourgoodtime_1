<?php

namespace Tests\Feature;

use App\Models\PredictionFeedback;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PredictionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_feedback_saves_as_past(): void
    {
        $request = $this->predictionRequest();
        $result = $this->predictionResult($request, 'past');

        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'past'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('prediction_feedback', [
            'prediction_request_id' => $request->id,
            'prediction_result_id' => $result->id,
            'prediction_type' => 'past',
            'rating' => 5,
            'accuracy_band' => 'ABOVE_OR_EQUAL_90',
        ]);
    }

    public function test_future_feedback_saves_as_future(): void
    {
        $request = $this->predictionRequest();
        $result = $this->predictionResult($request, 'future');

        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'future'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('prediction_feedback', [
            'prediction_request_id' => $request->id,
            'prediction_result_id' => $result->id,
            'prediction_type' => 'future',
        ]);
    }

    public function test_invalid_rating_and_accuracy_are_rejected(): void
    {
        $request = $this->predictionRequest();
        $result = $this->predictionResult($request, 'past');

        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'past', [
            'rating' => 6,
            'accuracy_band' => 'WRONG',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'accuracy_band']);
    }

    public function test_duplicate_feedback_updates_existing_row_without_creating_duplicate(): void
    {
        $request = $this->predictionRequest();
        $result = $this->predictionResult($request, 'past');

        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'past'))
            ->assertOk();
        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'past', [
            'rating' => 3,
            'accuracy_band' => 'FROM_50_TO_74',
            'comments' => 'Updated.',
        ]))
            ->assertOk();

        $this->assertSame(1, PredictionFeedback::query()
            ->where('prediction_result_id', $result->id)
            ->where('prediction_type', 'past')
            ->count());
        $this->assertDatabaseHas('prediction_feedback', [
            'prediction_result_id' => $result->id,
            'prediction_type' => 'past',
            'rating' => 3,
            'accuracy_band' => 'FROM_50_TO_74',
            'comments' => 'Updated.',
        ]);
    }

    public function test_another_request_cannot_submit_feedback(): void
    {
        $owner = $this->predictionRequest();
        $other = $this->predictionRequest();
        $result = $this->predictionResult($owner, 'past');

        $this->postJson("/api/predictions/{$other->id}/feedback", $this->feedbackPayload($other, $result, 'past'))
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('prediction_feedback', 0);
    }

    public function test_invalid_request_uuid_is_rejected(): void
    {
        $request = $this->predictionRequest();
        $result = $this->predictionResult($request, 'past');

        $this->postJson("/api/predictions/{$request->id}/feedback", $this->feedbackPayload($request, $result, 'past', [
            'request_uuid' => (string) Str::uuid(),
        ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('prediction_feedback', 0);
    }

    private function feedbackPayload(PredictionRequest $request, PredictionResult $result, string $predictionType, array $overrides = []): array
    {
        return array_merge([
            'request_uuid' => $request->request_uuid,
            'prediction_result_id' => $result->id,
            'prediction_type' => $predictionType,
            'rating' => 5,
            'accuracy_band' => 'ABOVE_OR_EQUAL_90',
            'comments' => 'Matched closely.',
        ], $overrides);
    }

    private function predictionRequest(): PredictionRequest
    {
        return PredictionRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'prediction_type' => 'past',
            'status' => 'completed',
            'full_name' => 'Ravi Kumar',
            'date_of_birth' => '1973-12-16',
            'time_of_birth' => '06:45',
            'timezone' => 'Asia/Kolkata',
            'place_of_birth' => 'Chennai',
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'birth_payload' => [],
            'raw_astrology_response' => [],
            'normalized_astrology_data' => [],
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function predictionResult(PredictionRequest $request, string $predictionType): PredictionResult
    {
        return PredictionResult::query()->create([
            'prediction_request_id' => $request->id,
            'prediction_type' => $predictionType,
            'identity_video_id' => '34',
            'result_payload' => ['type' => $predictionType],
            'summary_payload' => [],
            'warnings_payload' => [],
            'status' => 'completed',
            'generated_at' => now(),
        ]);
    }
}
