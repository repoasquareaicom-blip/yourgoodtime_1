<?php

namespace App\Http\Controllers;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\TempleRemedyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'cards' => [
                    'users' => User::query()->count(),
                    'past_requests' => PredictionRequest::query()->where('prediction_type', 'past')->count(),
                    'future_requests' => PredictionResult::query()->where('prediction_type', 'future')->count(),
                    'temple_remedies' => TempleRemedyRequest::query()->count(),
                ],
                'latest' => [
                    'users' => User::query()->latest()->limit(5)->get()->map(fn (User $user): array => $this->userPayload($user))->values(),
                    'temple_remedies' => TempleRemedyRequest::query()->with('user')->latest('submitted_at')->limit(5)->get()->map(fn (TempleRemedyRequest $request): array => $this->templeRemedyPayload($request))->values(),
                ],
            ],
        ]);
    }

    public function users(): JsonResponse
    {
        $users = User::query()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (User $user): array => $this->userPayload($user));

        return response()->json([
            'success' => true,
            'data' => $users->values(),
        ]);
    }

    public function predictionRequests(Request $request): JsonResponse
    {
        $type = $request->query('type', 'past');

        if ($type === 'future') {
            $results = PredictionResult::query()
                ->with('predictionRequest')
                ->where('prediction_type', 'future')
                ->latest('generated_at')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (PredictionResult $result): array => $this->predictionResultPayload($result));

            return response()->json([
                'success' => true,
                'data' => $results->values(),
            ]);
        }

        $requests = PredictionRequest::query()
            ->with('results')
            ->where('prediction_type', 'past')
            ->latest('submitted_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PredictionRequest $predictionRequest): array => $this->predictionRequestPayload($predictionRequest));

        return response()->json([
            'success' => true,
            'data' => $requests->values(),
        ]);
    }

    public function predictionRequest(PredictionRequest $predictionRequest): JsonResponse
    {
        $predictionRequest->load('results');

        return response()->json([
            'success' => true,
            'data' => $this->predictionRequestPayload($predictionRequest, true),
        ]);
    }

    public function templeRemedies(): JsonResponse
    {
        $requests = TempleRemedyRequest::query()
            ->with('user')
            ->latest('submitted_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TempleRemedyRequest $request): array => $this->templeRemedyPayload($request));

        return response()->json([
            'success' => true,
            'data' => $requests->values(),
        ]);
    }

    public function updateTempleRemedy(Request $request, TempleRemedyRequest $templeRemedy): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['RECEIVED', 'REVIEWING', 'PAYMENT_PENDING', 'PAID', 'PREPARING_REMEDY', 'REMEDY_READY', 'COMPLETED'])],
            'payment_enabled' => ['required', 'boolean'],
            'amount_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_status' => ['required', Rule::in(['NOT_REQUIRED', 'PENDING', 'PAID', 'FAILED', 'REFUNDED'])],
            'remedy_type' => ['nullable', Rule::in(['pdf', 'image', 'video', 'link'])],
            'remedy_title' => ['nullable', 'string', 'max:160'],
            'remedy_url' => ['nullable', 'url', 'max:2048'],
            'admin_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $updates = [
            'status' => $validated['status'],
            'payment_enabled' => (bool) $validated['payment_enabled'],
            'amount_minor' => $validated['amount_minor'] ?? null,
            'currency' => strtoupper($validated['currency'] ?? $templeRemedy->currency ?? 'INR'),
            'payment_status' => $validated['payment_status'],
            'remedy_type' => $validated['remedy_type'] ?? null,
            'remedy_title' => $validated['remedy_title'] ?? null,
            'remedy_url' => $validated['remedy_url'] ?? null,
            'admin_note' => $validated['admin_note'] ?? null,
        ];

        if ($updates['payment_enabled'] && $templeRemedy->payment_enabled_at === null) {
            $updates['payment_enabled_at'] = now();
        }

        if ($updates['payment_status'] === 'PAID' && $templeRemedy->paid_at === null) {
            $updates['paid_at'] = now();
        }

        if (($updates['remedy_url'] ?? null) && $templeRemedy->remedy_uploaded_at === null) {
            $updates['remedy_uploaded_at'] = now();
        }

        $templeRemedy->forceFill($updates)->save();

        return response()->json([
            'success' => true,
            'message' => 'Temple remedy request updated.',
            'data' => $this->templeRemedyPayload($templeRemedy->refresh()->load('user')),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_e164' => $user->phone_e164,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toJSON(),
            'last_login_at' => $user->last_login_at?->toJSON(),
        ];
    }

    private function predictionResultPayload(PredictionResult $result): array
    {
        $request = $result->predictionRequest;

        return [
            'id' => $result->id,
            'prediction_request_id' => $request?->id,
            'prediction_type' => $result->prediction_type,
            'status' => $result->status,
            'generated_at' => $result->generated_at?->toJSON(),
            'identity' => $request ? $this->identityPayload($request) : null,
            'engine' => $this->enginePayload($request, $result),
        ];
    }

    private function predictionRequestPayload(PredictionRequest $predictionRequest, bool $includeRaw = false): array
    {
        $results = $predictionRequest->results ?? collect();

        return [
            'id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'prediction_type' => $predictionRequest->prediction_type,
            'status' => $predictionRequest->status,
            'email' => $predictionRequest->email,
            'identity' => $this->identityPayload($predictionRequest),
            'astrology' => $this->astrologyPayload($predictionRequest),
            'engine' => $this->enginePayload($predictionRequest, $results->first()),
            'submitted_at' => $predictionRequest->submitted_at?->toJSON(),
            'completed_at' => $predictionRequest->completed_at?->toJSON(),
            'results' => $results->map(fn (PredictionResult $result): array => [
                'id' => $result->id,
                'prediction_type' => $result->prediction_type,
                'status' => $result->status,
                'generated_at' => $result->generated_at?->toJSON(),
                'videos_count' => count(data_get($result->result_payload, 'prediction_videos', [])),
            ])->values(),
            'raw' => $includeRaw ? [
                'birth_payload' => $predictionRequest->birth_payload,
                'normalized_astrology_data' => $predictionRequest->normalized_astrology_data,
                'raw_astrology_response' => $predictionRequest->raw_astrology_response,
            ] : null,
        ];
    }

    private function identityPayload(PredictionRequest $request): array
    {
        return [
            'name' => $request->full_name,
            'email' => $request->email,
            'date_of_birth' => $request->date_of_birth?->toDateString(),
            'time_of_birth' => $request->time_of_birth,
            'place_of_birth' => $request->place_of_birth,
            'country' => $request->country,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'timezone' => $request->timezone,
            'marriage_status' => $request->marriage_status,
        ];
    }

    private function astrologyPayload(PredictionRequest $request): array
    {
        $identity = data_get($request->normalized_astrology_data, 'identity', []);

        return array_filter([
            'rasi' => data_get($identity, 'rasi'),
            'nakshatra' => data_get($identity, 'nakshatra'),
            'lagnam' => data_get($identity, 'lagnam'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function enginePayload(?PredictionRequest $request, ?PredictionResult $result): array
    {
        return [
            'api_calls' => $request?->api_call_count,
            'cache_hit' => $request ? (bool) $request->cache_hit : null,
            'reused_from_request_id' => $request?->reused_from_request_id,
            'result_status' => $result?->status,
            'identity_video_id' => $result?->identity_video_id,
            'videos_count' => $result ? count(data_get($result->result_payload, 'prediction_videos', [])) : 0,
            'warnings_count' => $result ? count($result->warnings_payload ?? []) : 0,
            'error_message' => $request?->error_message,
        ];
    }

    private function templeRemedyPayload(TempleRemedyRequest $request): array
    {
        return [
            'id' => $request->id,
            'user' => $request->user ? $this->userPayload($request->user) : null,
            'problem_text' => $request->problem_text,
            'status' => $request->status,
            'payment_enabled' => (bool) $request->payment_enabled,
            'amount_minor' => $request->amount_minor,
            'currency' => $request->currency,
            'payment_status' => $request->payment_status,
            'remedy_type' => $request->remedy_type,
            'remedy_title' => $request->remedy_title,
            'remedy_url' => $request->remedy_url,
            'admin_note' => $request->admin_note,
            'feedback_rating' => $request->feedback_rating,
            'feedback_comments' => $request->feedback_comments,
            'submitted_at' => $request->submitted_at?->toJSON(),
            'payment_enabled_at' => $request->payment_enabled_at?->toJSON(),
            'paid_at' => $request->paid_at?->toJSON(),
            'remedy_uploaded_at' => $request->remedy_uploaded_at?->toJSON(),
        ];
    }
}
