<?php

namespace App\Http\Controllers;

use App\Models\PredictionRequest;
use App\Models\AstrologerAppointment;
use App\Models\PredictionEntitlement;
use App\Models\PredictionFeedback;
use App\Models\PredictionResult;
use App\Models\TempleRemedyRequest;
use App\Models\User;
use App\Services\Prediction\PredictionEngineService;
use App\Services\Prediction\PredictionResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $ratingSummary = $this->ratingSummary();

        return response()->json([
            'success' => true,
            'data' => [
                'cards' => [
                    'users' => User::query()->count(),
                    'past_requests' => PredictionRequest::query()->where('prediction_type', 'past')->count(),
                    'future_requests' => PredictionResult::query()->where('prediction_type', 'future')->count(),
                    'temple_remedies' => TempleRemedyRequest::query()->count(),
                    'pending_appointments' => AstrologerAppointment::query()->whereIn('status', ['open', 'pending', 'reviewing', 'slot_proposed', 'payment_pending'])->count(),
                    'upcoming_appointments' => AstrologerAppointment::query()->whereIn('status', ['slot_fixed', 'booked', 'upcoming'])->count(),
                    'ratings' => $ratingSummary,
                ],
                'latest' => [
                    'users' => User::query()->latest()->limit(5)->get()->map(fn (User $user): array => $this->userPayload($user))->values(),
                    'temple_remedies' => TempleRemedyRequest::query()->with('user')->latest('submitted_at')->limit(5)->get()->map(fn (TempleRemedyRequest $request): array => $this->templeRemedyPayload($request))->values(),
                ],
            ],
        ]);
    }

    private function ratingSummary(): array
    {
        $predictionRows = PredictionFeedback::query()
            ->whereNotNull('rating')
            ->get(['prediction_type', 'rating'])
            ->map(fn (PredictionFeedback $feedback): array => [
                'category' => strtolower((string) $feedback->prediction_type) === 'future' ? 'future' : 'past',
                'rating' => (float) $feedback->rating,
            ]);

        $templeRows = TempleRemedyRequest::query()
            ->whereNotNull('feedback_rating')
            ->get(['feedback_rating'])
            ->map(fn (TempleRemedyRequest $request): array => [
                'category' => 'temple_remedy',
                'rating' => (float) $request->feedback_rating,
            ]);

        $rows = $predictionRows->concat($templeRows)->values();
        $average = fn ($items): ?float => $items->count() ? round((float) $items->avg('rating'), 1) : null;

        return [
            'overall' => [
                'average' => $average($rows),
                'count' => $rows->count(),
            ],
            'past' => [
                'average' => $average($rows->where('category', 'past')),
                'count' => $rows->where('category', 'past')->count(),
            ],
            'future' => [
                'average' => $average($rows->where('category', 'future')),
                'count' => $rows->where('category', 'future')->count(),
            ],
            'temple_remedy' => [
                'average' => $average($rows->where('category', 'temple_remedy')),
                'count' => $rows->where('category', 'temple_remedy')->count(),
            ],
        ];
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

    public function feedback(): JsonResponse
    {
        $predictionFeedback = PredictionFeedback::query()
            ->latest('submitted_at')
            ->limit(200)
            ->get();
        $predictionRequests = PredictionRequest::query()
            ->whereIn('id', $predictionFeedback->pluck('prediction_request_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');
        $usersByEmail = User::query()
            ->whereIn('email', $predictionRequests->pluck('email')->filter()->unique()->values())
            ->get()
            ->keyBy(fn (User $user): string => strtolower(trim((string) $user->email)));

        $predictionRows = $predictionFeedback->map(function (PredictionFeedback $feedback) use ($predictionRequests, $usersByEmail): array {
            $predictionRequest = $predictionRequests->get($feedback->prediction_request_id);
            $user = $predictionRequest?->email ? $usersByEmail->get(strtolower(trim((string) $predictionRequest->email))) : null;
            $category = strtolower((string) $feedback->prediction_type) === 'future' ? 'future' : 'past';

            return $this->feedbackPayloadForAdmin(
                category: $category,
                categoryLabel: $category === 'future' ? 'Future Prediction' : 'Past Prediction',
                id: 'prediction-'.$feedback->id,
                rating: $feedback->rating,
                comments: $feedback->comments,
                submittedAt: $feedback->submitted_at?->toJSON(),
                user: $user,
                context: [
                    'request_id' => $feedback->prediction_request_id,
                    'result_id' => $feedback->prediction_result_id,
                    'accuracy_band' => $feedback->accuracy_band,
                    'name' => $predictionRequest?->full_name,
                    'email' => $predictionRequest?->email,
                    'prediction_type' => $feedback->prediction_type,
                    'submitted_at' => $predictionRequest?->submitted_at?->toJSON(),
                ]
            );
        });

        $templeRows = TempleRemedyRequest::query()
            ->with('user')
            ->whereNotNull('feedback_submitted_at')
            ->latest('feedback_submitted_at')
            ->limit(200)
            ->get()
            ->map(fn (TempleRemedyRequest $request): array => $this->feedbackPayloadForAdmin(
                category: 'temple_remedy',
                categoryLabel: 'Temple Remedy',
                id: 'temple-'.$request->id,
                rating: $request->feedback_rating,
                comments: $request->feedback_comments,
                submittedAt: $request->feedback_submitted_at?->toJSON(),
                user: $request->user,
                context: [
                    'request_id' => $request->id,
                    'selected_remedy' => $request->remedy_option_title,
                    'remedy_title' => $request->remedy_title,
                    'payment_status' => $request->payment_status,
                    'status' => $request->status,
                    'completed_at' => $request->completed_at?->toJSON(),
                ]
            ));

        $rows = $predictionRows
            ->concat($templeRows)
            ->sortByDesc('submitted_at')
            ->values();

        return response()->json([
            'success' => true,
            'counts' => [
                'all' => $rows->count(),
                'past' => $rows->where('category', 'past')->count(),
                'future' => $rows->where('category', 'future')->count(),
                'temple_remedy' => $rows->where('category', 'temple_remedy')->count(),
            ],
            'data' => $rows,
        ]);
    }

    public function predictionRequestDetails(Request $request, PredictionRequest $predictionRequest): JsonResponse
    {
        $type = strtolower((string) $request->query('type', $predictionRequest->prediction_type ?: 'past'));
        $entitlementId = $request->integer('entitlement_id') ?: null;
        $resultId = $request->integer('result_id') ?: null;

        $query = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $type);

        if ($resultId) {
            $query->whereKey($resultId);
        }

        if ($type === 'future' && $entitlementId) {
            $query->where('prediction_entitlement_id', $entitlementId);
        }

        $result = $query->latest('generated_at')->latest('id')->first();

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to load prediction request.',
            ], 404);
        }

        $entitlement = $result->prediction_entitlement_id
            ? PredictionEntitlement::query()->find($result->prediction_entitlement_id)
            : null;
        $feedback = $this->feedbackForResult($result);
        $period = $this->predictionPeriod($predictionRequest, $result, $entitlement);

        return response()->json([
            'success' => true,
            'data' => [
                'request' => $this->predictionRequestPayload($predictionRequest),
                'result' => $this->predictionResultPayload($result),
                'entitlement' => $entitlement ? $this->entitlementPayload($entitlement) : null,
                'period' => $period,
                'rating' => $this->feedbackPayload($feedback),
                'last_visited_at' => $feedback?->submitted_at?->toJSON(),
                'viewer_url' => $this->predictionResponseViewerUrl($predictionRequest),
                'identity_video' => $this->identityVideoRows($result),
                'prediction_data' => $this->predictionDataRows($result),
                'videos' => $this->predictionVideoRows($result),
            ],
        ]);
    }

    public function regeneratePastPrediction(
        Request $request,
        PredictionRequest $predictionRequest,
        PredictionEngineService $predictionEngineService,
        PredictionResponseBuilder $predictionResponseBuilder
    ): JsonResponse {
        if ($predictionRequest->prediction_type !== 'past') {
            return response()->json([
                'success' => false,
                'message' => 'Only past prediction requests can be regenerated from this action.',
            ], 422);
        }

        $existingResult = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', 'past')
            ->latest('id')
            ->first();

        try {
            $timeline = $predictionEngineService->preparePredictionTimeline($predictionRequest->id, 'past');
            $result = $predictionResponseBuilder->buildTimeline($timeline);
            $warnings = collect($result['prediction_videos'] ?? [])
                ->pluck('warning')
                ->filter()
                ->values()
                ->all();
            $payload = [
                'prediction_request_id' => $result['prediction_request_id'] ?? $predictionRequest->id,
                'type' => $result['prediction_type'] ?? 'past',
                'identity_video' => $result['identity_video'] ?? null,
                'prediction_videos' => $result['prediction_videos'] ?? [],
                'summary' => $result['summary'] ?? [],
                'warnings' => $warnings,
            ];

            if ($payload['prediction_videos'] === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Past prediction regeneration did not produce any prediction videos.',
                ], 422);
            }

            $savedResult = PredictionResult::query()->updateOrCreate(
                [
                    'prediction_request_id' => $predictionRequest->id,
                    'prediction_type' => 'past',
                ],
                [
                    'user_id' => $existingResult?->user_id ?? $this->userIdForEmail($predictionRequest->email),
                    'prediction_entitlement_id' => null,
                    'identity_video_id' => data_get($payload, 'identity_video.video_id'),
                    'result_payload' => $payload,
                    'summary_payload' => $payload['summary'] ?? [],
                    'warnings_payload' => $payload['warnings'] ?? [],
                    'status' => 'completed',
                    'generated_at' => now(),
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('Admin past prediction regeneration failed.', [
                'prediction_request_id' => $predictionRequest->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to regenerate past prediction result.',
            ], 422);
        }

        $predictionRequest->loadMissing('results');
        $feedback = $this->feedbackForResult($savedResult);
        $period = $this->predictionPeriod($predictionRequest, $savedResult, null);

        return response()->json([
            'success' => true,
            'message' => 'Past prediction regenerated successfully.',
            'data' => [
                'request' => $this->predictionRequestPayload($predictionRequest),
                'result' => $this->predictionResultPayload($savedResult),
                'entitlement' => null,
                'period' => $period,
                'rating' => $this->feedbackPayload($feedback),
                'last_visited_at' => $feedback?->submitted_at?->toJSON(),
                'viewer_url' => $this->predictionResponseViewerUrl($predictionRequest),
                'identity_video' => $this->identityVideoRows($savedResult),
                'prediction_data' => $this->predictionDataRows($savedResult),
                'videos' => $this->predictionVideoRows($savedResult),
            ],
        ]);
    }

    public function templeRemedies(Request $request): JsonResponse
    {
        $group = strtolower((string) $request->query('group', 'payment_pending'));

        $requests = TempleRemedyRequest::query()
            ->with('user')
            ->when($group === 'completed', fn ($query) => $query->where('status', 'COMPLETED'))
            ->when($group === 'payment_received', fn ($query) => $query->where('status', '!=', 'COMPLETED')->where('payment_status', 'PAID'))
            ->when(! in_array($group, ['payment_received', 'completed'], true), fn ($query) => $query->where('status', '!=', 'COMPLETED')->where('payment_status', 'PENDING'))
            ->latest('submitted_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TempleRemedyRequest $request): array => $this->templeRemedyPayload($request));

        return response()->json([
            'success' => true,
            'counts' => [
                'payment_pending' => TempleRemedyRequest::query()->where('status', '!=', 'COMPLETED')->where('payment_status', 'PENDING')->count(),
                'payment_received' => TempleRemedyRequest::query()->where('status', '!=', 'COMPLETED')->where('payment_status', 'PAID')->count(),
                'completed' => TempleRemedyRequest::query()->where('status', 'COMPLETED')->count(),
            ],
            'data' => $requests->values(),
        ]);
    }

    public function updateTempleRemedy(Request $request, TempleRemedyRequest $templeRemedy): JsonResponse
    {
        $incomingStatus = strtoupper((string) $request->input('status'));
        $hasExistingFile = (bool) ($templeRemedy->remedy_file_path || $templeRemedy->remedy_url);
        $rules = [
            'status' => ['required', Rule::in(['PAYMENT_PENDING', 'COMPLETED'])],
            'payment_status' => ['nullable', Rule::in(['PENDING', 'PAID'])],
            'payment_enabled' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'amount_inr' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'remedy_type' => ['prohibited'],
            'remedy_title' => [$incomingStatus === 'COMPLETED' ? 'required' : 'nullable', 'string', 'max:160'],
            'remedy_url' => ['nullable', 'url', 'max:2048'],
            'remedy_file' => [$incomingStatus === 'COMPLETED' && ! $hasExistingFile ? 'required' : 'nullable', 'file', 'max:51200', 'mimetypes:application/pdf'],
            'admin_note' => ['nullable', 'string', 'max:4000'],
        ];

        $validated = Validator::make($request->all(), $rules, [
            'amount_inr.required' => 'Enter the payment amount.',
            'amount_inr.gt' => 'Enter the payment amount.',
            'remedy_title.required' => 'Enter the remedy title.',
            'remedy_file.required' => 'Upload the prepared remedy.',
            'remedy_file.mimetypes' => 'Upload the remedy as a PDF file.',
            'payment_enabled.prohibited' => 'Payment requirement is managed by the request status.',
        ])->validate();

        if (! $this->canTransitionTempleRemedyStatus($templeRemedy->status, $validated['status'])) {
            return response()->json([
                'success' => false,
                'message' => $templeRemedy->status === 'COMPLETED'
                    ? 'This request has already been completed.'
                    : 'This status transition is not allowed.',
                'errors' => ['status' => ['This status transition is not allowed.']],
            ], 422);
        }

        if ($validated['status'] === 'COMPLETED' && $templeRemedy->payment_status !== 'PAID' && ($validated['payment_status'] ?? null) !== 'PAID') {
            return response()->json([
                'success' => false,
                'message' => 'Payment must be received before completing this Temple Remedy.',
                'errors' => ['payment_status' => ['Payment must be received before completing this Temple Remedy.']],
            ], 422);
        }

        $amountMinor = array_key_exists('amount_inr', $validated) && $validated['amount_inr'] !== null && $validated['amount_inr'] !== ''
            ? (int) round(((float) $validated['amount_inr']) * 100)
            : $templeRemedy->amount_minor;

        $uploadedPath = null;
        if ($request->hasFile('remedy_file')) {
            $uploadedPath = $request->file('remedy_file')->store('temple-remedies');
        }

        $updates = [
            'status' => $validated['status'],
            'payment_enabled' => true,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($validated['currency'] ?? $templeRemedy->currency ?? 'INR'),
            'payment_status' => $this->paymentStatusForAdminStatus($validated['status'], $templeRemedy, $validated['payment_status'] ?? null),
            'remedy_type' => $validated['remedy_type'] ?? $templeRemedy->remedy_type,
            'remedy_title' => $validated['remedy_title'] ?? $templeRemedy->remedy_title,
            'remedy_url' => $validated['remedy_url'] ?? $templeRemedy->remedy_url,
            'admin_note' => $validated['admin_note'] ?? null,
        ];

        if ($uploadedPath) {
            $file = $request->file('remedy_file');
            $updates['remedy_file_path'] = $uploadedPath;
            $updates['remedy_file_name'] = $file->getClientOriginalName();
            $updates['remedy_mime_type'] = (string) $file->getMimeType();
            $updates['remedy_file_size'] = $file->getSize();
            $updates['remedy_url'] = null;
            $updates['remedy_type'] = $this->remedyTypeFromMime((string) $file->getMimeType());
            $updates['remedy_uploaded_at'] = now();
        }

        if ($updates['payment_enabled'] && $templeRemedy->payment_enabled_at === null) {
            $updates['payment_enabled_at'] = now();
        }

        if (($updates['remedy_url'] ?? null) && $templeRemedy->remedy_uploaded_at === null) {
            $updates['remedy_uploaded_at'] = now();
        }

        $shouldSendCompletionMail = $validated['status'] === 'COMPLETED' && $templeRemedy->completed_at === null;
        if ($shouldSendCompletionMail) {
            $updates['completed_at'] = now();
            $updates['completed_by_admin_id'] = $request->user()->id;
            $updates['completion_method'] = ($updates['payment_status'] ?? $templeRemedy->payment_status) === 'PAID' ? 'payment' : 'manual';
        }

        if (($updates['payment_status'] ?? null) === 'PAID' && $templeRemedy->paid_at === null) {
            $updates['paid_at'] = now();
        }

        $templeRemedy->forceFill($updates)->save();
        $templeRemedy->refresh()->load('user');

        if ($shouldSendCompletionMail) {
            $this->sendTempleRemedyCompletionMail($templeRemedy);
        }

        return response()->json([
            'success' => true,
            'message' => 'Temple remedy request updated.',
            'data' => $this->templeRemedyPayload($templeRemedy),
        ]);
    }

    public function templeRemedyAstrology(TempleRemedyRequest $templeRemedy): JsonResponse
    {
        $templeRemedy->load('user');

        $predictionRequest = $templeRemedy->prediction_request_id
            ? PredictionRequest::query()->find($templeRemedy->prediction_request_id)
            : null;

        if (! $predictionRequest && $templeRemedy->user?->email) {
            $predictionRequest = PredictionRequest::query()
                ->where('email', $templeRemedy->user->email)
                ->latest('submitted_at')
                ->latest('id')
                ->first();
        }

        if (! $predictionRequest || ! hash_equals((string) $predictionRequest->email, (string) $templeRemedy->user?->email)) {
            return response()->json([
                'success' => true,
                'available' => false,
                'message' => 'Astrology data is not available for this request.',
                'data' => null,
            ]);
        }

        $normalized = $predictionRequest->normalized_astrology_data;
        $value = is_array(data_get($normalized, 'value')) ? data_get($normalized, 'value') : (is_array($normalized) ? $normalized : []);

        return response()->json([
            'success' => true,
            'available' => true,
            'prediction_request_id' => $predictionRequest->id,
            'viewer_url' => $this->predictionResponseViewerUrl($predictionRequest),
            'data' => [
                'birth' => data_get($value, 'birth', []),
                'identity' => data_get($value, 'identity', []),
                'chart' => data_get($value, 'chart', []),
                'planets' => $this->planetDisplayRows(data_get($value, 'planets', [])),
                'dasha_major' => $this->dashaMajorDisplayRows(data_get($value, 'dasha.major', [])),
                'raw_sections' => array_filter([
                    'dasha' => data_get($value, 'dasha'),
                    'raw_astrology_response' => $predictionRequest->raw_astrology_response,
                ]),
            ],
        ]);
    }

    public function templeRemedyManualPaymentFile(TempleRemedyRequest $templeRemedy)
    {
        $path = data_get($templeRemedy->manual_payment, 'path');
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment proof is unavailable.',
            ], 404);
        }

        return response()->file(Storage::disk('local')->path($path), array_filter([
            'Content-Type' => data_get($templeRemedy->manual_payment, 'mime_type'),
        ]));
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
        $entitlement = $result->prediction_entitlement_id
            ? PredictionEntitlement::query()->find($result->prediction_entitlement_id)
            : null;
        $feedback = $this->feedbackForResult($result);
        $period = $request ? $this->predictionPeriod($request, $result, $entitlement) : null;

        return [
            'id' => $result->id,
            'prediction_request_id' => $request?->id,
            'prediction_result_id' => $result->id,
            'prediction_entitlement_id' => $result->prediction_entitlement_id,
            'prediction_type' => $result->prediction_type,
            'status' => $result->status,
            'created_at' => $result->created_at?->toJSON(),
            'generated_at' => $result->generated_at?->toJSON(),
            'last_visited_at' => $feedback?->submitted_at?->toJSON(),
            'rating' => $this->feedbackPayload($feedback),
            'period' => $period,
            'entitlement' => $entitlement ? $this->entitlementPayload($entitlement) : null,
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
            'created_at' => $predictionRequest->created_at?->toJSON(),
            'submitted_at' => $predictionRequest->submitted_at?->toJSON(),
            'completed_at' => $predictionRequest->completed_at?->toJSON(),
            'last_visited_at' => $results->first() ? $this->feedbackForResult($results->first())?->submitted_at?->toJSON() : null,
            'rating' => $results->first() ? $this->feedbackPayload($this->feedbackForResult($results->first())) : $this->feedbackPayload(null),
            'period' => $results->first() ? $this->predictionPeriod($predictionRequest, $results->first()) : null,
            'prediction_result_id' => $results->first()?->id,
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

    private function feedbackForResult(PredictionResult $result): ?PredictionFeedback
    {
        return PredictionFeedback::query()
            ->where('prediction_result_id', $result->id)
            ->where('prediction_type', $result->prediction_type)
            ->latest('submitted_at')
            ->first();
    }

    private function feedbackPayload(?PredictionFeedback $feedback): array
    {
        return [
            'rating' => $feedback?->rating,
            'accuracy_band' => $feedback?->accuracy_band,
            'comments' => $feedback?->comments,
            'submitted_at' => $feedback?->submitted_at?->toJSON(),
            'label' => $feedback ? $feedback->rating.' / 5' : 'Not Rated',
        ];
    }

    private function feedbackPayloadForAdmin(
        string $category,
        string $categoryLabel,
        string $id,
        mixed $rating,
        ?string $comments,
        ?string $submittedAt,
        ?User $user,
        array $context
    ): array {
        return [
            'id' => $id,
            'category' => $category,
            'category_label' => $categoryLabel,
            'rating' => $rating,
            'rating_label' => $rating ? $rating.' / 5' : 'Not rated',
            'comments' => $comments,
            'submitted_at' => $submittedAt,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?: ($context['name'] ?? null),
                'email' => $user?->email ?: ($context['email'] ?? null),
            ],
            'context' => $context,
        ];
    }

    private function entitlementPayload(PredictionEntitlement $entitlement): array
    {
        return [
            'id' => $entitlement->id,
            'prediction_request_id' => $entitlement->prediction_request_id,
            'payment_transaction_id' => $entitlement->payment_transaction_id,
            'access_start_year' => $entitlement->access_start_year,
            'access_end_year' => $entitlement->access_end_year,
            'start_age' => $entitlement->start_age,
            'end_age' => $entitlement->end_age,
            'purchased_years' => $entitlement->purchased_years,
            'status' => $entitlement->status,
            'purchased_at' => $entitlement->purchased_at?->toJSON(),
        ];
    }

    private function predictionResponseViewerUrl(PredictionRequest $predictionRequest): string
    {
        $expires = now()->addMinutes(30)->timestamp;
        $payload = implode('|', [$predictionRequest->id, 1, $expires]);
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return url('/view-prediction-response.php').'?'.http_build_query([
            'id' => $predictionRequest->id,
            'embedded' => 1,
            'expires' => $expires,
            'signature' => $signature,
        ]);
    }

    private function storedAstrologyPayload(PredictionRequest $request): array
    {
        $normalized = $request->normalized_astrology_data;
        $value = is_array(data_get($normalized, 'value')) ? data_get($normalized, 'value') : (is_array($normalized) ? $normalized : []);

        return [
            'identity' => $this->identityPayload($request),
            'summary' => $this->astrologyPayload($request),
            'birth' => data_get($value, 'birth', []),
            'chart' => data_get($value, 'chart', []),
            'planets' => $this->planetDisplayRows(data_get($value, 'planets', [])),
            'dasha_major' => $this->dashaMajorDisplayRows(data_get($value, 'dasha.major', [])),
        ];
    }

    private function planetIdNameMap(): array
    {
        return [
            0 => 'Sun',
            1 => 'Moon',
            2 => 'Mars',
            3 => 'Mercury',
            4 => 'Jupiter',
            5 => 'Venus',
            6 => 'Saturn',
            7 => 'Rahu',
            8 => 'Ketu',
        ];
    }

    private function planetNameFromId(mixed $planetId): ?string
    {
        if ($planetId === null || $planetId === '') {
            return null;
        }

        $map = $this->planetIdNameMap();
        $id = (int) $planetId;

        return array_key_exists($id, $map) ? $map[$id] : null;
    }

    private function rowValueByKeys(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        $normalizedKeys = array_map('strtolower', $keys);
        foreach ($row as $key => $value) {
            if (in_array(strtolower((string) $key), $normalizedKeys, true)) {
                return $value;
            }
        }

        return null;
    }

    private function planetDisplayRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $planetId = $this->rowValueByKeys($row, ['planet_id', 'id']);
                $planetName = $this->planetNameFromId($planetId);

                if ($planetName !== null) {
                    $row['name'] = $planetName;
                }

                return $row;
            })
            ->values()
            ->all();
    }

    private function dashaMajorDisplayRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $planetId = $this->rowValueByKeys($row, ['planet_id', 'id']);
                $planetName = $this->planetNameFromId($planetId);

                if ($planetName !== null) {
                    $row['planet'] = $planetName;
                }

                return $row;
            })
            ->values()
            ->all();
    }

    private function predictionPeriod(PredictionRequest $request, PredictionResult $result, ?PredictionEntitlement $entitlement = null): array
    {
        $videos = $this->flattenPredictionVideos($result);
        $years = collect($videos)
            ->flatMap(fn (array $video): array => [
                $video['system_year_from'] ?? null,
                $video['system_year_to'] ?? null,
                $video['system_year'] ?? null,
            ])
            ->filter(fn ($year): bool => is_numeric($year))
            ->map(fn ($year): int => (int) $year)
            ->values();
        $ages = collect($videos)
            ->pluck('age')
            ->filter(fn ($age): bool => is_numeric($age))
            ->map(fn ($age): int => (int) $age)
            ->values();

        $startYear = $entitlement?->access_start_year ?? ($years->isNotEmpty() ? $years->min() : null);
        $endYear = $entitlement?->access_end_year ?? ($years->isNotEmpty() ? $years->max() : null);

        return [
            'start_year' => $startYear,
            'end_year' => $endYear,
            'start_age' => $entitlement?->start_age ?? ($ages->isNotEmpty() ? $ages->min() : null),
            'end_age' => $entitlement?->end_age ?? ($ages->isNotEmpty() ? $ages->max() : null),
            'label' => $startYear && $endYear ? $startYear.'-'.$endYear : 'Not available',
        ];
    }

    private function identityVideoRows(PredictionResult $result): array
    {
        $identity = data_get($result->result_payload, 'identity_video');

        if (! is_array($identity)) {
            return [];
        }

        $source = is_array($identity['source'] ?? null) ? $identity['source'] : [];
        $storedIdentity = data_get($result->predictionRequest?->normalized_astrology_data, 'value.identity', []);

        return [[
            'excel_file' => $source['file'] ?? 'Not recorded',
            'sheet_name' => $source['sheet'] ?? 'Not recorded',
            'rasi' => $source['rasi'] ?? data_get($storedIdentity, 'rasi'),
            'nakshatra' => $source['nakshatra'] ?? data_get($storedIdentity, 'nakshatra'),
            'lagnam' => $source['lagnam'] ?? data_get($storedIdentity, 'lagnam'),
            'excel_row_number' => $source['excel_row_number'] ?? $source['excel_row'] ?? 'Not recorded',
            'video_id' => $identity['video_id'] ?? $source['video'] ?? null,
            'status_warning' => $source['warning'] ?? $source['status'] ?? null,
        ]];
    }

    private function predictionDataRows(PredictionResult $result): array
    {
        return collect(data_get($result->result_payload, 'prediction_videos', []))
            ->sortBy(fn (array $video): int => (int) ($video['age'] ?? $video['system_year'] ?? 999999))
            ->values()
            ->map(fn (array $video): array => [
                'age' => $this->rangeLabel($video['age_from'] ?? null, $video['age_to'] ?? null)
                    ?? $video['age_label']
                    ?? $video['age'] ?? null,
                'system_year' => $this->rangeLabel($video['system_year_from'] ?? null, $video['system_year_to'] ?? null)
                    ?? $video['system_year_label']
                    ?? $video['system_year'] ?? null,
                'system_year_range' => $this->rangeLabel($video['system_year_from'] ?? null, $video['system_year_to'] ?? null),
                'dasha' => $video['major_dasha'] ?? 'Not recorded',
                'dasha_period' => $video['dasha_year_label'] ?? $this->rangeLabel($video['dasha_start_date'] ?? null, $video['dasha_end_date'] ?? null) ?? 'Not recorded',
                'excel_file' => $video['workbook'] ?? $video['excel_file'] ?? 'Not recorded',
                'sheet_name' => $video['sheet'] ?? $video['worksheet'] ?? 'Not recorded',
                'excel_row_number' => $video['excel_row_number'] ?? $video['excel_row'] ?? $video['matched_row'] ?? 'Not recorded',
                'excel_year_column' => $video['excel_year_column'] ?? $video['matched_year'] ?? $video['excel_sequence_year'] ?? 'Not recorded',
                'excel_year' => $this->rangeLabel($video['excel_sequence_year_from'] ?? null, $video['excel_sequence_year_to'] ?? null)
                    ?? $video['excel_sequence_year'] ?? 'Not recorded',
                'excel_cell' => $video['excel_cell'] ?? 'Not recorded',
                'video_id' => implode(', ', $this->normalizedVideoIds($video)),
                'status_warning' => $video['warning'] ?? $video['status'] ?? null,
            ])
            ->all();
    }

    private function predictionVideoRows(PredictionResult $result): array
    {
        $sequence = 0;

        return collect($this->flattenPredictionVideos($result))
            ->values()
            ->flatMap(function (array $video) use (&$sequence): array {
                $videoIds = $this->normalizedVideoIds($video);

                if ($videoIds === []) {
                    $videoIds = [null];
                }

                return array_map(function ($videoId) use ($video, &$sequence): array {
                    $sequence++;

                    return [
                        'sequence' => $sequence,
                        'age' => $video['age'] ?? null,
                        'year' => $video['system_year'] ?? $video['system_year_from'] ?? null,
                        'video_id' => $videoId,
                        'video_title' => null,
                        'source' => $video['workbook'] ?? null,
                        'playback_status' => 'Not tracked',
                        'last_viewed_at' => null,
                    ];
                }, $videoIds);
            })
            ->all();
    }

    private function normalizedVideoIds(array $video): array
    {
        $rawVideoIds = $video['video_ids'] ?? [];

        if (is_string($rawVideoIds) || is_numeric($rawVideoIds)) {
            $rawVideoIds = preg_split('/[\s,]+/', (string) $rawVideoIds) ?: [];
        }

        if (! is_array($rawVideoIds)) {
            $rawVideoIds = [];
        }

        if ($rawVideoIds === [] && ! empty($video['video_id'])) {
            $rawVideoIds = [$video['video_id']];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($videoId): string => trim((string) $videoId), $rawVideoIds),
            fn (string $videoId): bool => $videoId !== ''
        )));
    }

    private function flattenPredictionVideos(PredictionResult $result): array
    {
        return collect(data_get($result->result_payload, 'prediction_videos', []))
            ->flatMap(fn (array $video): array => isset($video['items']) && is_array($video['items']) ? $video['items'] : [$video])
            ->values()
            ->all();
    }

    private function rangeLabel(mixed $from, mixed $to): ?string
    {
        if ($from && $to && (string) $from !== (string) $to) {
            return $from.'-'.$to;
        }

        return $from ? (string) $from : null;
    }

    private function userIdForEmail(?string $email): ?int
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return User::query()
            ->where('email', strtolower(trim($email)))
            ->value('id');
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
            'prediction_request_id' => $request->prediction_request_id,
            'user' => $request->user ? $this->userPayload($request->user) : null,
            'remedy_option' => [
                'code' => $request->remedy_option_code,
                'title' => $request->remedy_option_title,
            ],
            'problem_text' => $request->problem_text,
            'contact_number' => $request->contact_number,
            'alternate_contact_number' => $request->alternate_contact_number,
            'preferred_language' => $request->preferred_language,
            'payment_method' => $request->payment_method_code,
            'manual_payment' => [
                'reference' => data_get($request->manual_payment, 'reference'),
                'original_name' => data_get($request->manual_payment, 'original_name'),
                'mime_type' => data_get($request->manual_payment, 'mime_type'),
                'uploaded_at' => data_get($request->manual_payment, 'uploaded_at'),
                'file_url' => data_get($request->manual_payment, 'path') ? url('/api/admin/temple-remedies/'.$request->id.'/manual-payment-file') : null,
            ],
            'status' => $request->status,
            'payment_enabled' => (bool) $request->payment_enabled,
            'amount_minor' => $request->amount_minor,
            'currency' => $request->currency,
            'payment_status' => $request->payment_status,
            'remedy_type' => $request->remedy_type,
            'remedy_title' => $request->remedy_title,
            'remedy_file_path' => $request->remedy_file_path,
            'remedy_file_name' => $request->remedy_file_name,
            'remedy_mime_type' => $request->remedy_mime_type,
            'remedy_file_size' => $request->remedy_file_size,
            'remedy_url' => $request->remedy_url,
            'admin_note' => $request->admin_note,
            'feedback_rating' => $request->feedback_rating,
            'feedback_comments' => $request->feedback_comments,
            'submitted_at' => $request->submitted_at?->toJSON(),
            'payment_enabled_at' => $request->payment_enabled_at?->toJSON(),
            'paid_at' => $request->paid_at?->toJSON(),
            'remedy_uploaded_at' => $request->remedy_uploaded_at?->toJSON(),
            'completed_at' => $request->completed_at?->toJSON(),
            'completion_method' => $request->completion_method,
        ];
    }

    private function canTransitionTempleRemedyStatus(string $currentStatus, string $nextStatus): bool
    {
        if ($currentStatus === $nextStatus) {
            return true;
        }

        if ($currentStatus === 'COMPLETED') {
            return false;
        }

        if ($currentStatus === 'PAYMENT_PENDING') {
            return $nextStatus === 'COMPLETED';
        }

        return match ($currentStatus) {
            'RECEIVED' => in_array($nextStatus, ['REVIEWING', 'PAYMENT_PENDING', 'COMPLETED'], true),
            'REVIEWING' => in_array($nextStatus, ['PAYMENT_PENDING', 'COMPLETED'], true),
            default => in_array($nextStatus, ['REVIEWING', 'PAYMENT_PENDING', 'COMPLETED'], true),
        };
    }

    private function paymentStatusForAdminStatus(string $status, TempleRemedyRequest $request, ?string $requestedPaymentStatus = null): string
    {
        if ($status === 'COMPLETED') {
            return $request->payment_status === 'PAID' || $requestedPaymentStatus === 'PAID' ? 'PAID' : 'NOT_REQUIRED';
        }

        if ($requestedPaymentStatus === 'PAID') {
            return 'PAID';
        }

        if ($request->payment_status === 'PAID') {
            return 'PAID';
        }

        return 'PENDING';
    }

    private function sendTempleRemedyCompletionMail(TempleRemedyRequest $request): void
    {
        $email = $request->user?->email;
        if (! $email) {
            return;
        }

        try {
            Mail::raw(
                "Dear ".($request->user?->name ?: 'Customer').",\n\n".
                "Your Temple Remedy request #{$request->id} has been completed.\n\n".
                "Selected remedy: ".($request->remedy_option_title ?: 'Temple Remedy')."\n".
                "Remedy title: ".($request->remedy_title ?: 'Temple Remedy Details')."\n\n".
                "Remedy details:\n".($request->admin_note ?: 'Please find the attached remedy details.')."\n\n".
                "The remedy file is attached to this email. You can also view it from My Account.\n\n".
                "Regards,\nYour Good Time",
                function ($message) use ($request, $email): void {
                    $message->to($email)->subject('Your Temple Remedy details are ready - Your Good Time');

                    if ($request->remedy_file_path && Storage::disk('local')->exists($request->remedy_file_path)) {
                        $message->attach(Storage::disk('local')->path($request->remedy_file_path), [
                            'as' => $request->remedy_file_name ?: 'temple-remedy.pdf',
                            'mime' => $request->remedy_mime_type ?: 'application/pdf',
                        ]);
                    }
                }
            );
        } catch (\Throwable $exception) {
            Log::warning('Temple Remedy completion email failed.', [
                'request_id' => $request->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function remedyTypeFromMime(string $mime): string
    {
        if (str_contains($mime, 'pdf')) {
            return 'pdf';
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'link';
    }
}
