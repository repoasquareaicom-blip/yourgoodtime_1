<?php

namespace App\Http\Controllers;

use App\Models\PredictionEntitlement;
use App\Models\AstrologerAppointment;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\TempleRemedyRequest;
use App\Models\AppointmentPaymentMethod;
use App\Models\User;
use App\Services\FuturePredictionAccessService;
use App\Services\AstrologerAppointmentService;
use App\Services\Prediction\PredictionTimelineSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyAccountPredictionController extends Controller
{
    public function index(
        Request $request,
        PredictionTimelineSummaryService $timelineSummaryService,
        FuturePredictionAccessService $futureAccessService,
        AstrologerAppointmentService $appointmentService
    ): JsonResponse {
        $user = $request->user();
        $pastRequest = $this->latestCompletedPastRequest((string) $user->email);
        $purchaseRequest = $this->futurePurchaseRequest((string) $user->email, $user->id);

        return response()->json([
            'success' => true,
            'data' => [
                'identity' => $this->identityPayload($user, $pastRequest ?? $purchaseRequest),
                'past' => $this->pastPayload($pastRequest, $timelineSummaryService),
                'future' => $this->futurePayload($user, $purchaseRequest, $futureAccessService),
                'temple_remedies' => $this->templeRemedyPayload($user),
                'appointments' => $this->appointmentPayload($user, $appointmentService),
            ],
        ]);
    }

    private function latestCompletedPastRequest(string $email): ?PredictionRequest
    {
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '') {
            return null;
        }

        return PredictionRequest::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail])
            ->where('status', 'completed')
            ->whereNotNull('date_of_birth')
            ->whereNotNull('time_of_birth')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('timezone')
            ->whereNotNull('birth_payload')
            ->whereNotNull('raw_astrology_response')
            ->whereNotNull('normalized_astrology_data')
            ->latest('completed_at')
            ->latest('id')
            ->first();
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

    private function pastPayload(?PredictionRequest $predictionRequest, PredictionTimelineSummaryService $timelineSummaryService): array
    {
        if ($predictionRequest === null) {
            return [
                'available' => false,
                'action' => 'get',
                'message' => 'Complete your birth details to prepare your Past Prediction.',
            ];
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

        return [
            'available' => true,
            'action' => 'watch',
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

        $ranges = $entitlements->map(function (PredictionEntitlement $entitlement) use ($currentYear): array {
            $hasResult = PredictionResult::query()
                ->where('prediction_entitlement_id', $entitlement->id)
                ->where('prediction_type', 'future')
                ->where('status', 'completed')
                ->exists();

            $sourceRequest = PredictionRequest::query()->find($entitlement->prediction_request_id);

            return [
                'entitlement_id' => $entitlement->id,
                'start_year' => max((int) $entitlement->access_start_year, $currentYear + 1),
                'end_year' => $entitlement->access_end_year,
                'has_result' => $hasResult,
                'watch' => $sourceRequest ? $this->watchPayload($sourceRequest) : null,
            ];
        })->values();

        $nextPurchase = $predictionRequest
            ? $futureAccessService->nextPurchaseBlock($user, $predictionRequest)
            : null;

        return [
            'purchased' => $ranges->isNotEmpty(),
            'ranges' => $ranges,
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
