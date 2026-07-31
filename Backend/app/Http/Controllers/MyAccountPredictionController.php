<?php

namespace App\Http\Controllers;

use App\Models\PredictionEntitlement;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\TempleRemedyRequest;
use App\Models\User;
use App\Services\FuturePredictionAccessService;
use App\Services\Prediction\PredictionTimelineSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyAccountPredictionController extends Controller
{
    public function index(
        Request $request,
        PredictionTimelineSummaryService $timelineSummaryService,
        FuturePredictionAccessService $futureAccessService
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
        return [
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
        ];
    }

    private function templeRemedyPayload(User $user): array
    {
        return TempleRemedyRequest::query()
            ->where('user_id', $user->id)
            ->latest('submitted_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(function (TempleRemedyRequest $remedyRequest): array {
                return [
                    'id' => $remedyRequest->id,
                    'problem_text' => $remedyRequest->problem_text,
                    'status' => $remedyRequest->status,
                    'status_label' => match ($remedyRequest->status) {
                        'RECEIVED' => 'Request received',
                        'REVIEWING' => 'Under review',
                        'PAYMENT_PENDING' => 'Payment pending',
                        'PAID' => 'Payment completed',
                        'PREPARING_REMEDY' => 'Preparing the remedy',
                        'REMEDY_READY' => 'Remedy ready',
                        'COMPLETED' => 'Completed',
                        default => ucwords(strtolower(str_replace('_', ' ', $remedyRequest->status))),
                    },
                    'payment_enabled' => $remedyRequest->payment_enabled,
                    'amount_minor' => $remedyRequest->amount_minor,
                    'currency' => $remedyRequest->currency,
                    'payment_status' => $remedyRequest->payment_status,
                    'submitted_at' => $remedyRequest->submitted_at?->toJSON(),
                    'remedy' => [
                        'available' => (bool) ($remedyRequest->remedy_url || $remedyRequest->remedy_file_path),
                        'type' => $remedyRequest->remedy_type,
                        'title' => $remedyRequest->remedy_title,
                        'url' => $remedyRequest->remedy_url,
                        'uploaded_at' => $remedyRequest->remedy_uploaded_at?->toJSON(),
                    ],
                    'admin_note' => $remedyRequest->admin_note,
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
