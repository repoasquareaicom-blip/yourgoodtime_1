<?php

namespace App\Http\Controllers;

use App\Models\PredictionEntitlement;
use App\Models\FutureVideoManualPayment;
use App\Models\AstrologerAppointment;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\TempleRemedyRequest;
use App\Models\AppointmentPaymentMethod;
use App\Models\User;
use App\Services\FuturePredictionAccessService;
use App\Services\AstrologerAppointmentService;
use App\Services\Prediction\PredictionTimelineSummaryService;
use App\Services\ProtectedVideoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class MyAccountPredictionController extends Controller
{
    public function index(
        Request $request,
        PredictionTimelineSummaryService $timelineSummaryService,
        FuturePredictionAccessService $futureAccessService,
        AstrologerAppointmentService $appointmentService
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard($request->user(), $timelineSummaryService, $futureAccessService, $appointmentService),
        ]);
    }

    public function adminShow(
        User $user,
        PredictionTimelineSummaryService $timelineSummaryService,
        FuturePredictionAccessService $futureAccessService,
        AstrologerAppointmentService $appointmentService
    ): JsonResponse {
        $data = $this->dashboard($user, $timelineSummaryService, $futureAccessService, $appointmentService);
        unset($data['past']['watch']);
        $data['future']['ranges'] = $data['future']['ranges']->map(fn (array $range): array => [
            ...Arr::except($range, ['watch']),
            'can_open' => $range['watch'] !== null,
        ]);
        $data['temple_remedies'] = collect($data['temple_remedies'])->map(fn (array $remedy): array => [
            ...Arr::only($remedy, ['id', 'remedy_option', 'status', 'status_label', 'payment_status', 'payment_method',
                'submitted_at', 'amount_minor', 'currency', 'can_pay', 'admin_note']),
            'remedy' => Arr::only($remedy['remedy'], ['available']),
            'feedback' => Arr::only($remedy['feedback'], ['submitted']),
        ]);
        $data['appointments'] = [
            'groups' => array_map(fn ($items) => $items->map(fn (array $appointment): array => [
                ...Arr::only($appointment, ['id', 'appointment_number', 'status', 'status_label', 'questions_count',
                    'payment_status', 'payment_status_label', 'submitted_at', 'confirmed_start_at', 'preferred_date',
                    'preferred_time', 'amount_minor', 'currency', 'can_pay']),
                'has_meeting_link' => filled($appointment['meeting_link']),
            ]), $data['appointments']['groups']),
        ];
        $data['user'] = ['id' => $user->id];

        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'no-store');
    }

    public function adminPrediction(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:past,future'],
            'entitlement_id' => ['required_if:type,future', 'integer'],
        ]);
        $result = $this->previewPrediction($user, $validated['type'], $validated['entitlement_id'] ?? null);

        return response()->json(['success' => true, 'data' => [
            'result_id' => $result->id,
            'identity_video' => data_get($result->result_payload, 'identity_video'),
            'identity_video_id' => $result->identity_video_id,
            'prediction_videos' => data_get($result->result_payload, 'prediction_videos', []),
        ]])->header('Cache-Control', 'no-store');
    }

    public function adminPlaybackUrl(Request $request, User $user, ProtectedVideoService $videoService): JsonResponse
    {
        $validated = $request->validate([
            'prediction_type' => ['required', 'in:past,future'],
            'entitlement_id' => ['required_if:prediction_type,future', 'integer'],
            'prediction_result_id' => ['required', 'integer'],
            'video_id' => ['required', 'string', 'max:160'],
            'video_role' => ['required', 'in:IDENTITY,PREDICTION'],
            'language' => ['required', 'in:ta,en,hi'],
        ]);
        $result = $this->previewPrediction($user, $validated['prediction_type'], $validated['entitlement_id'] ?? null);
        abort_unless($result->id === (int) $validated['prediction_result_id'], 404);

        try {
            $url = $videoService->createPlaybackUrl($user, [
                ...$validated,
                'prediction_request_id' => $result->prediction_request_id,
                'request_uuid' => $result->predictionRequest->request_uuid,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'This prediction video is unavailable.',
            ], method_exists($exception, 'status') ? $exception->status() : 403);
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]])->header('Cache-Control', 'no-store');
    }

    private function previewPrediction(User $user, string $type, ?int $entitlementId): PredictionResult
    {
        $query = PredictionResult::query()->with('predictionRequest')
            ->where('prediction_type', $type)
            ->where('status', 'completed');

        if ($type === 'future') {
            $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
            $entitlement = PredictionEntitlement::query()->where('user_id', $user->id)
                ->where('prediction_type', 'future')->where('status', 'ACTIVE')
                ->where('access_end_year', '>', $currentYear)
                ->findOrFail($entitlementId);
            $query->where('prediction_entitlement_id', $entitlement->id)
                ->where('prediction_request_id', $entitlement->prediction_request_id);
        } else {
            $profile = $this->latestCompletedPastRequest($user->email);
            abort_if($profile === null, 404, 'This prediction is not ready to watch.');
            $query->where('prediction_request_id', $profile->id);
        }

        $result = $query->latest('generated_at')->latest('id')->first();
        abort_unless($result?->hasReadingVideos(), 404, 'This prediction is not ready to watch.');

        return $result;
    }

    private function dashboard(
        User $user,
        PredictionTimelineSummaryService $timelineSummaryService,
        FuturePredictionAccessService $futureAccessService,
        AstrologerAppointmentService $appointmentService
    ): array {
        $pastRequest = $this->latestCompletedPastRequest((string) $user->email);
        $purchaseRequest = $this->futurePurchaseRequest((string) $user->email, $user->id);

        return [
            'identity' => $this->identityPayload($user, $pastRequest ?? $purchaseRequest),
            'past' => $this->pastPayload($pastRequest, $timelineSummaryService, $user),
            'future' => $this->futurePayload($user, $purchaseRequest, $futureAccessService),
            'temple_remedies' => $this->templeRemedyPayload($user),
            'appointments' => $this->appointmentPayload($user, $appointmentService),
        ];
    }

    private function latestCompletedPastRequest(string $email): ?PredictionRequest
    {
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '') {
            return null;
        }

        return PredictionRequest::query()->completedBirthProfile($normalizedEmail)->first();
    }

    private function futurePurchaseRequest(string $email, int $userId): ?PredictionRequest
    {
        $entitlementRequest = PredictionEntitlement::query()
            ->where('user_id', $userId)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->latest('access_end_year')
            ->value('prediction_request_id');

        if ($entitlementRequest !== null) {
            return PredictionRequest::query()->find((int) $entitlementRequest);
        }

        return $this->latestCompletedPastRequest($email);
    }

    private function pastPayload(?PredictionRequest $predictionRequest, PredictionTimelineSummaryService $timelineSummaryService, User $user): array
    {
        if ($predictionRequest === null) {
            return [
                'available' => false,
                'action' => 'get',
                'message' => 'Complete your birth details to prepare your Past Prediction.',
            ];
        }

        $manualPayment = FutureVideoManualPayment::query()
            ->where('user_id', $user->id)
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('service_type', FutureVideoManualPayment::PAST_SERVICE_TYPE)
            ->where('status', FutureVideoManualPayment::VERIFIED)
            ->latest('id')->first();
        if (data_get($manualPayment?->metadata, 'activation_pending')) {
            return ['available' => false, 'action' => 'preparing'];
        }

        $birthDate = $predictionRequest->date_of_birth;

        if ($birthDate === null) {
            return [
                'available' => false,
                'action' => 'get',
                'message' => 'Your Past Prediction range is not available right now.',
            ];
        }

        $range = $timelineSummaryService->pastRange(
            CarbonImmutable::instance($birthDate),
            CarbonImmutable::now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))
        );

        if (! $range['available']) {
            return [
                'available' => false,
                'action' => 'get',
                'message' => 'Your Past Prediction range is not available right now.',
            ];
        }

        $result = $predictionRequest->results()->where('prediction_type', 'past')->where('status', 'completed')->first();

        return [
            'available' => true,
            'action' => 'watch',
            'watch_ready' => (bool) $result?->hasReadingVideos(),
            'start_year' => $range['start_year'],
            'end_year' => $range['end_year'],
            'watch' => $this->watchPayload($predictionRequest),
        ];
    }

    private function futurePayload(User $user, ?PredictionRequest $predictionRequest, FuturePredictionAccessService $futureAccessService): array
    {
        $currentYear = (int) CarbonImmutable::now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $entitlements = PredictionEntitlement::query()
            ->where('user_id', $user->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->where('access_end_year', '>', $currentYear)
            ->orderBy('access_start_year')
            ->get();

        $payments = FutureVideoManualPayment::query()
            ->where('user_id', $user->id)
            ->where('service_type', FutureVideoManualPayment::SERVICE_TYPE)
            ->whereIn('status', [FutureVideoManualPayment::AWAITING, FutureVideoManualPayment::PENDING, FutureVideoManualPayment::VERIFIED])
            ->latest('id')->get();
        $manualPayments = $payments->keyBy('id');
        $ranges = $entitlements->load('paymentTransaction')->map(function (PredictionEntitlement $entitlement) use ($currentYear, $manualPayments): array {
            $result = PredictionResult::query()
                ->where('prediction_entitlement_id', $entitlement->id)
                ->where('prediction_type', 'future')
                ->where('status', 'completed')
                ->first();

            $sourceRequest = PredictionRequest::query()->find($entitlement->prediction_request_id);
            $manualPayment = $manualPayments->get(data_get($entitlement->paymentTransaction?->metadata, 'future_video_manual_payment_id'));
            $preparing = data_get($manualPayment?->metadata, 'source') === 'manual_admin'
                && (bool) data_get($manualPayment?->metadata, 'activation_pending');

            return [
                'entitlement_id' => $entitlement->id,
                'start_year' => max((int) $entitlement->access_start_year, $currentYear + 1),
                'end_year' => $entitlement->access_end_year,
                'has_result' => $result !== null,
                'watch_ready' => ! $preparing && (bool) $result?->hasReadingVideos(),
                'watch' => $sourceRequest && ! $preparing ? $this->watchPayload($sourceRequest) : null,
            ];
        })->values();

        $nextPurchase = $predictionRequest
            ? $futureAccessService->nextPurchaseBlock($user, $predictionRequest)
            : null;
        $resume = $payments->firstWhere('status', FutureVideoManualPayment::AWAITING);

        return [
            'purchased' => $ranges->isNotEmpty(),
            'ranges' => $ranges,
            'payment_pending' => $payments->contains('status', FutureVideoManualPayment::PENDING),
            'activation_pending' => $payments->contains(fn (FutureVideoManualPayment $payment): bool => (bool) data_get($payment->metadata, 'activation_pending')),
            'resume_payment' => $resume ? [
                'id' => $resume->id,
                'status' => $resume->status,
                'amount_minor' => $resume->amount_minor,
                'currency' => $resume->currency,
                'product_name' => $resume->product?->name,
                'period' => data_get($resume->metadata, 'next_purchase'),
            ] : null,
            'next_purchase' => $nextPurchase ? [
                'available' => (bool) ($nextPurchase['can_purchase'] ?? false),
                'start_year' => $nextPurchase['start_year'] ?? null,
                'end_year' => $nextPurchase['end_year'] ?? null,
                'message' => $nextPurchase['message'] ?? null,
            ] : [
                'available' => false,
                'start_year' => null,
                'end_year' => null,
                'message' => 'Complete your birth details to unlock your Future Prediction.',
            ],
        ];
    }

    private function identityPayload(User $user, ?PredictionRequest $predictionRequest): array
    {
        $normalizedData = $predictionRequest?->normalized_astrology_data ?? [];
        $astrologyIdentity = $normalizedData['identity'] ?? [];

        return [
            'name' => $predictionRequest?->full_name ?: $user->name,
            'email' => $predictionRequest?->email ?: $user->email,
            'submitted_details' => $predictionRequest ? [
                'gender' => $predictionRequest->gender,
                'date_of_birth' => $predictionRequest->date_of_birth?->toDateString(),
                'time_of_birth' => $predictionRequest->time_of_birth,
                'marriage_status' => $predictionRequest->marriage_status,
                'place_of_birth' => $predictionRequest->place_of_birth,
                'country' => $predictionRequest->country,
                'timezone' => $predictionRequest->timezone,
            ] : null,
            'astrology' => array_filter([
                'lagnam' => data_get($astrologyIdentity, 'lagnam'),
                'nakshatra' => data_get($astrologyIdentity, 'nakshatra'),
                'rasi' => data_get($astrologyIdentity, 'rasi'),
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function watchPayload(PredictionRequest $predictionRequest): array
    {
        $identityVideoId = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('status', 'completed')
            ->whereNotNull('identity_video_id')
            ->latest('generated_at')
            ->latest('id')
            ->value('identity_video_id');

        return [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
            'identity_video_id' => $identityVideoId ? (string) $identityVideoId : null,
        ];
    }

    private function rangeLabel(mixed $from, mixed $to): ?string
    {
        if ($from && $to && (string) $from !== (string) $to) {
            return $from.'-'.$to;
        }

        return $from ? (string) $from : null;
    }

    private function appointmentPayload(User $user, AstrologerAppointmentService $appointmentService): array
    {
        $appointments = AstrologerAppointment::query()
            ->with('questions')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AstrologerAppointment $appointment): array => $appointmentService->payload($appointment))
            ->values();

        return [
            'config' => $appointmentService->configPayload(),
            'items' => $appointments,
            'groups' => [
                'pending' => $appointments->filter(fn (array $item): bool => in_array($item['status'], ['open', 'pending', 'reviewing', 'slot_proposed', 'payment_pending'], true))->values(),
                'upcoming' => $appointments->filter(fn (array $item): bool => in_array($item['status'], ['slot_fixed', 'booked', 'upcoming'], true))->values(),
                'completed' => $appointments->filter(fn (array $item): bool => $item['status'] === 'completed')->values(),
            ],
        ];
    }

    private function templeRemedyPayload(User $user): array
    {
        return TempleRemedyRequest::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['PAYMENT_CANCELLED', 'CANCELLED'])
            ->where(function ($query): void {
                $query
                    ->where('payment_method_code', '!=', AppointmentPaymentMethod::PAYMENT_GATEWAY)
                    ->orWhere('payment_status', 'PAID')
                    ->orWhere('status', 'COMPLETED');
            })
            ->latest('submitted_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(function (TempleRemedyRequest $remedyRequest): array {
                $hasRemedy = (bool) ($remedyRequest->remedy_url || $remedyRequest->remedy_file_path);
                $canShowRemedy = $hasRemedy && $remedyRequest->status === 'COMPLETED';
                $fileEndpoint = url('/api/temple-remedies/'.$remedyRequest->id.'/file');

                return [
                    'id' => $remedyRequest->id,
                    'problem_text' => $remedyRequest->problem_text,
                    'remedy_option' => [
                        'code' => $remedyRequest->remedy_option_code,
                        'title' => $remedyRequest->remedy_option_title,
                    ],
                    'contact_number' => $remedyRequest->contact_number,
                    'alternate_contact_number' => $remedyRequest->alternate_contact_number,
                    'preferred_language' => $remedyRequest->preferred_language,
                    'payment_method' => $remedyRequest->payment_method_code,
                    'status' => $remedyRequest->status,
                    'status_label' => $remedyRequest->payment_status === 'PAID' && $remedyRequest->status !== 'COMPLETED'
                        ? 'Payment Received'
                        : match ($remedyRequest->status) {
                        'RECEIVED' => 'Received',
                        'REVIEWING' => 'Reviewing',
                        'PAYMENT_PENDING' => 'Payment Pending',
                        'COMPLETED' => 'Completed',
                        default => ucwords(strtolower(str_replace('_', ' ', $remedyRequest->status))),
                    },
                    'payment_enabled' => $remedyRequest->payment_enabled,
                    'amount_minor' => $remedyRequest->amount_minor,
                    'currency' => $remedyRequest->currency,
                    'payment_status' => $remedyRequest->payment_status,
                    'can_pay' => $remedyRequest->payment_method_code === AppointmentPaymentMethod::PAYMENT_GATEWAY
                        && $remedyRequest->status === 'PAYMENT_PENDING'
                        && $remedyRequest->payment_status !== 'PAID',
                    'submitted_at' => $remedyRequest->submitted_at?->toJSON(),
                    'remedy' => [
                        'available' => $canShowRemedy,
                        'type' => $canShowRemedy ? $remedyRequest->remedy_type : null,
                        'title' => $canShowRemedy ? $remedyRequest->remedy_title : null,
                        'file_name' => $canShowRemedy ? $remedyRequest->remedy_file_name : null,
                        'mime_type' => $canShowRemedy ? $remedyRequest->remedy_mime_type : null,
                        'file_size' => $canShowRemedy ? $remedyRequest->remedy_file_size : null,
                        'url' => $canShowRemedy ? $fileEndpoint : null,
                        'download_url' => $canShowRemedy ? $fileEndpoint.'?download=1' : null,
                        'uploaded_at' => $canShowRemedy ? $remedyRequest->remedy_uploaded_at?->toJSON() : null,
                    ],
                    'admin_note' => $canShowRemedy ? $remedyRequest->admin_note : null,
                    'feedback' => [
                        'submitted' => $remedyRequest->feedback_submitted_at !== null,
                        'rating' => $remedyRequest->feedback_rating,
                        'comments' => $remedyRequest->feedback_comments,
                        'submitted_at' => $remedyRequest->feedback_submitted_at?->toJSON(),
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
