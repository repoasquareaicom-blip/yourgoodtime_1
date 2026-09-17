<?php

namespace App\Services;

use App\Models\PredictionEntitlement;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProtectedVideoService
{
    public function createPlaybackUrl(User $user, array $input): string
    {
        return $this->createPlaybackPayload($user, $input)['url'];
    }

    public function createPlaybackPayload(User $user, array $input): array
    {
        $videoId = $this->normalizeVideoId((string) $input['video_id']);
        $predictionType = strtolower((string) $input['prediction_type']);
        $requestedRole = $this->normalizeVideoRole($input['video_role'] ?? null);
        $language = $this->normalizeLanguage($input['language'] ?? null);
        $predictionRequest = PredictionRequest::query()
            ->whereKey((int) $input['prediction_request_id'])
            ->first();

        if ($predictionRequest === null || ! hash_equals($predictionRequest->request_uuid, (string) $input['request_uuid'])) {
            throw new ProtectedVideoPlaybackException('Prediction access is invalid.', [
                'authorization_result' => 'FAILED',
                'authorization_reason' => 'REQUEST_UUID_MISMATCH',
                'video_role' => $requestedRole,
                'video_id' => $videoId,
                'final_video_id' => $videoId,
                'language' => $language,
                'prediction_period' => $predictionType,
            ]);
        }

        $result = $this->authorizedResultContainingVideo(
            user: $user,
            predictionRequest: $predictionRequest,
            predictionType: $predictionType,
            videoId: $videoId,
            videoRole: $requestedRole,
            resultId: isset($input['prediction_result_id']) ? (int) $input['prediction_result_id'] : null,
            entitlementId: isset($input['entitlement_id']) ? (int) $input['entitlement_id'] : null,
        );

        if ($result === null) {
            throw new ProtectedVideoPlaybackException(
                'You are not authorised to play this video.',
                $this->authorizationFailureDebug(
                    user: $user,
                    predictionRequest: $predictionRequest,
                    predictionType: $predictionType,
                    videoId: $videoId,
                    videoRole: $requestedRole,
                    resultId: isset($input['prediction_result_id']) ? (int) $input['prediction_result_id'] : null,
                    entitlementId: isset($input['entitlement_id']) ? (int) $input['entitlement_id'] : null,
                    language: $language,
                )
            );
        }

        $videoRole = $requestedRole ?? $this->roleForVideoId($result->result_payload ?? [], $videoId);
        $filenameResolution = $this->filenameResolutionForVideoId($videoId, $videoRole, $language);
        $filename = $filenameResolution['filename'];
        $debugPayload = [
            'video_id' => $videoId,
            'stored_video_id' => $videoRole === 'IDENTITY'
                ? $this->identityVideoIdForPredictionRequest($predictionRequest, collect([$result]))
                : $this->storedVideoIdForVideo($result, $videoId, $videoRole),
            'final_video_id' => $videoId,
            'video_id_source' => $videoRole === 'IDENTITY' ? 'IDENTITY_VIDEO' : 'STORED_RESULT',
            'filename' => $filename,
            'resolved_filename' => $filename,
            'filename_candidates' => $filenameResolution['candidates'],
            'video_role' => $videoRole,
            'prediction_period' => $predictionType,
            'language' => $language,
            'excel_file_path' => $this->excelFilePathForVideo($result->result_payload ?? [], $videoId, $videoRole, $predictionType),
            'prediction_request_id' => $predictionRequest->id,
            'prediction_result_id' => $result->id,
            'request_uuid' => $predictionRequest->request_uuid,
        ];

        if (! collect($filenameResolution['candidates'])->contains(fn (array $candidate): bool => $candidate['exists'])) {
            throw new ProtectedVideoPlaybackException('Video file not found.', array_merge($debugPayload, [
                'authorization_result' => 'PASSED',
                'availability_result' => 'UNAVAILABLE',
                'availability_reason' => 'VIDEO_FILE_MISSING',
            ]), 404);
        }

        $expiresAt = now()->addSeconds(max(60, (int) config('video.token_ttl_seconds', 600)));
        $token = Crypt::encryptString(json_encode([
            'uid' => $user->id,
            'prediction_request_id' => $predictionRequest->id,
            'prediction_result_id' => $result->id,
            'prediction_type' => $predictionType,
            'video_id' => $videoId,
            'video_role' => $videoRole,
            'language' => $language,
            'filename' => $filename,
            'exp' => $expiresAt->getTimestamp(),
            'nonce' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));

        return [
            'url' => url('/api/video/stream').'?token='.rawurlencode($token),
            'debug' => $debugPayload,
        ];
    }

    public function payloadFromToken(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid video token.');
        }

        if (! is_array($payload) || empty($payload['filename']) || empty($payload['video_id']) || empty($payload['exp'])) {
            throw new RuntimeException('Invalid video token.');
        }

        if ((int) $payload['exp'] < now()->getTimestamp()) {
            throw new RuntimeException('Video token has expired.');
        }

        $videoId = $this->normalizeVideoId((string) $payload['video_id']);
        $videoRole = $this->normalizeVideoRole($payload['video_role'] ?? null);
        $language = $this->normalizeLanguage($payload['language'] ?? null);
        $filename = $this->normalizeFilename((string) $payload['filename']);
        $allowedFilenames = $this->filenameCandidatesForVideoId($videoId, $videoRole, $language);

        if (! in_array($filename, $allowedFilenames, true)) {
            throw new RuntimeException('Invalid video token.');
        }

        return array_merge($payload, [
            'video_id' => $videoId,
            'video_role' => $videoRole,
            'language' => $language,
            'filename' => $filename,
        ]);
    }

    public function absolutePathForFilename(string $filename): ?string
    {
        $safeFilename = $this->normalizeFilename($filename);
        $disk = Storage::disk((string) config('video.protected_disk', 'protected_videos'));

        if ($disk->exists($safeFilename)) {
            return $disk->path($safeFilename);
        }

        if ((bool) config('video.public_contents_fallback', true)) {
            $legacyPath = public_path('contents'.DIRECTORY_SEPARATOR.$safeFilename);

            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }

        return null;
    }

    private function authorizedResultContainingVideo(
        User $user,
        PredictionRequest $predictionRequest,
        string $predictionType,
        string $videoId,
        ?string $videoRole,
        ?int $resultId,
        ?int $entitlementId,
    ): ?PredictionResult {
        if ($predictionType === 'past' && ! $this->userOwnsPastRequest($user, $predictionRequest)) {
            return null;
        }

        $query = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $predictionType)
            ->where('status', 'completed');

        if ($resultId !== null && $predictionType !== 'future') {
            $query->whereKey($resultId);
        }

        if ($predictionType === 'future') {
            $entitlementIds = $this->activeFutureEntitlementIds($user, $predictionRequest, $entitlementId);

            if ($entitlementIds === []) {
                return null;
            }

            $query->whereIn('prediction_entitlement_id', $entitlementIds);
        }

        $results = $query->get();

        if ($videoRole === 'IDENTITY') {
            $identityVideoId = $this->identityVideoIdForPredictionRequest($predictionRequest, $results);

            if ($identityVideoId !== null && $this->logicalVideoIdsMatch($identityVideoId, $videoId)) {
                return $results->first();
            }

            return null;
        }

        return $results
            ->first(fn (PredictionResult $result): bool => $this->resultContainsVideoId($result, $videoId, $videoRole));
    }

    private function activeFutureEntitlementIds(User $user, PredictionRequest $predictionRequest, ?int $entitlementId): array
    {
        $currentYear = (int) now(config('services.prediction_billing.business_timezone', 'Asia/Kolkata'))->format('Y');
        $query = PredictionEntitlement::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('user_id', $user->id)
            ->where('prediction_type', 'future')
            ->where('status', 'ACTIVE')
            ->where('access_end_year', '>', $currentYear);

        if ($entitlementId !== null) {
            $query->whereKey($entitlementId);
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function authorizationFailureDebug(
        User $user,
        PredictionRequest $predictionRequest,
        string $predictionType,
        string $videoId,
        ?string $videoRole,
        ?int $resultId,
        ?int $entitlementId,
        string $language,
    ): array {
        $resolvedRole = $videoRole ?? 'PREDICTION';
        $filenameResolution = null;

        try {
            $filenameResolution = $this->filenameResolutionForVideoId($videoId, $resolvedRole, $language);
        } catch (\Throwable) {
            $filenameResolution = ['filename' => null, 'candidates' => []];
        }

        $debug = [
            'authorization_result' => 'FAILED',
            'authorization_reason' => 'UNKNOWN',
            'video_role' => $resolvedRole,
            'video_id' => $videoId,
            'requested_video_id' => $videoId,
            'stored_video_id' => null,
            'final_video_id' => $videoId,
            'video_id_source' => $resolvedRole === 'IDENTITY' ? 'IDENTITY_VIDEO' : 'STORED_RESULT',
            'prediction_period' => $predictionType,
            'language' => $language,
            'filename' => $filenameResolution['filename'],
            'resolved_filename' => $filenameResolution['filename'],
            'filename_candidates' => $filenameResolution['candidates'],
            'prediction_request_id' => $predictionRequest->id,
            'request_uuid' => $predictionRequest->request_uuid,
        ];

        if ($predictionType === 'past' && ! $this->userOwnsPastRequest($user, $predictionRequest)) {
            return array_merge($debug, ['authorization_reason' => 'PREDICTION_REQUEST_OWNER_MISMATCH']);
        }

        $query = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $predictionType)
            ->where('status', 'completed');

        if ($resultId !== null && $predictionType !== 'future') {
            $query->whereKey($resultId);
        }

        if ($predictionType === 'future') {
            $entitlementIds = $this->activeFutureEntitlementIds($user, $predictionRequest, $entitlementId);

            if ($entitlementIds === []) {
                return array_merge($debug, ['authorization_reason' => 'FUTURE_ENTITLEMENT_UNAVAILABLE']);
            }

            $query->whereIn('prediction_entitlement_id', $entitlementIds);
        }

        $result = $query->first();

        if ($result === null) {
            return array_merge($debug, ['authorization_reason' => 'RESULT_NOT_FOUND']);
        }

        $debug['prediction_result_id'] = $result->id;

        if ($resolvedRole === 'IDENTITY') {
            $storedVideoId = $this->identityVideoIdForPredictionRequest($predictionRequest, collect([$result]));
            $debug['stored_video_id'] = $storedVideoId;

            return array_merge($debug, [
                'authorization_reason' => $storedVideoId === null
                    ? 'IDENTITY_VIDEO_MISSING'
                    : 'IDENTITY_VIDEO_MISMATCH',
            ]);
        }

        return array_merge($debug, ['authorization_reason' => 'PREDICTION_VIDEO_MISMATCH']);
    }

    private function resultContainsVideoId(PredictionResult $result, string $videoId, ?string $videoRole): bool
    {
        $payload = $result->result_payload ?? [];

        if ($videoRole !== 'PREDICTION') {
            $payloadIdentityVideoId = (string) data_get($payload, 'identity_video.video_id', '');
            $columnIdentityVideoId = (string) ($result->identity_video_id ?? '');

            if (
                ($payloadIdentityVideoId !== '' && $this->logicalVideoIdsMatch($payloadIdentityVideoId, $videoId))
                || ($columnIdentityVideoId !== '' && $this->logicalVideoIdsMatch($columnIdentityVideoId, $videoId))
            ) {
                return true;
            }
        }

        if ($videoRole === 'IDENTITY') {
            return false;
        }

        return $this->payloadContainsPredictionVideoId($payload, $videoId);
    }

    private function payloadContainsPredictionVideoId(array $payload, string $videoId): bool
    {
        foreach ((array) data_get($payload, 'prediction_videos', []) as $group) {
            foreach ($this->videoIdsFromGroup((array) $group) as $candidate) {
                if (hash_equals($candidate, $videoId)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function roleForVideoId(array $payload, string $videoId): string
    {
        $identityVideoId = (string) data_get($payload, 'identity_video.video_id', '');

        if ($identityVideoId !== '' && $this->logicalVideoIdsMatch($identityVideoId, $videoId)) {
            return 'IDENTITY';
        }

        return 'PREDICTION';
    }

    private function excelFilePathForVideo(array $payload, string $videoId, string $videoRole, string $predictionType): ?string
    {
        if ($videoRole === 'IDENTITY') {
            $file = (string) data_get($payload, 'identity_video.source.file', config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'));

            return $this->safeRelativeExcelPath($file);
        }

        foreach ((array) data_get($payload, 'prediction_videos', []) as $group) {
            foreach ($this->videoIdsFromGroup((array) $group) as $candidate) {
                if (! hash_equals($candidate, $videoId)) {
                    continue;
                }

                $workbook = (string) ($group['workbook'] ?? '');

                if ($workbook === '') {
                    return null;
                }

                return $this->safeRelativeExcelPath($predictionType.'/'.$workbook);
            }
        }

        return null;
    }

    private function storedVideoIdForVideo(PredictionResult $result, string $videoId, string $videoRole): ?string
    {
        $payload = $result->result_payload ?? [];

        if ($videoRole === 'IDENTITY') {
            $storedVideoId = (string) data_get($payload, 'identity_video.video_id', '');
            $storedVideoId = $storedVideoId === '' ? (string) ($result->identity_video_id ?? '') : $storedVideoId;

            return $storedVideoId === '' ? null : $storedVideoId;
        }

        foreach ((array) data_get($payload, 'prediction_videos', []) as $group) {
            foreach ($this->videoIdsFromGroup((array) $group) as $candidate) {
                if (hash_equals($candidate, $videoId)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function identityVideoIdForPredictionRequest(PredictionRequest $predictionRequest, iterable $preferredResults = []): ?string
    {
        foreach ($preferredResults as $result) {
            if (! $result instanceof PredictionResult) {
                continue;
            }

            $identityVideoId = $this->identityVideoIdFromResult($result);

            if ($identityVideoId !== null) {
                return $identityVideoId;
            }
        }

        $results = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('status', 'completed')
            ->latest('generated_at')
            ->latest('id')
            ->get();

        foreach ($results as $result) {
            $identityVideoId = $this->identityVideoIdFromResult($result);

            if ($identityVideoId !== null) {
                return $identityVideoId;
            }
        }

        return null;
    }

    private function identityVideoIdFromResult(PredictionResult $result): ?string
    {
        $payloadIdentityVideoId = (string) data_get($result->result_payload ?? [], 'identity_video.video_id', '');
        $columnIdentityVideoId = (string) ($result->identity_video_id ?? '');
        $identityVideoId = $payloadIdentityVideoId !== '' ? $payloadIdentityVideoId : $columnIdentityVideoId;

        return $identityVideoId === '' ? null : $identityVideoId;
    }

    private function safeRelativeExcelPath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || ! str_ends_with(strtolower($path), '.xlsx')) {
            return null;
        }

        return 'prediction-data/'.$path;
    }

    private function videoIdsFromGroup(array $group): array
    {
        $rawVideoIds = $group['video_ids'] ?? [];

        if ($rawVideoIds === [] && ! empty($group['video_id'])) {
            $rawVideoIds = [$group['video_id']];
        }

        if (is_string($rawVideoIds) || is_numeric($rawVideoIds)) {
            $rawVideoIds = preg_split('/[\s,]+/', (string) $rawVideoIds) ?: [];
        }

        if (is_array($rawVideoIds)) {
            return array_values(array_filter(array_map(
                fn (mixed $videoId): string => trim((string) $videoId),
                array_is_list($rawVideoIds) ? $rawVideoIds : array_values($rawVideoIds)
            )));
        }

        return [];
    }

    private function userOwnsPastRequest(User $user, PredictionRequest $predictionRequest): bool
    {
        return filled($user->email)
            && filled($predictionRequest->email)
            && strtolower(trim((string) $user->email)) === strtolower(trim((string) $predictionRequest->email));
    }

    private function logicalVideoIdsMatch(string $firstVideoId, string $secondVideoId): bool
    {
        try {
            return hash_equals(
                $this->canonicalFiveDigitVideoNumber($this->normalizeVideoId($firstVideoId)),
                $this->canonicalFiveDigitVideoNumber($this->normalizeVideoId($secondVideoId))
            );
        } catch (\Throwable) {
            return hash_equals($this->normalizeVideoId($firstVideoId), $this->normalizeVideoId($secondVideoId));
        }
    }

    private function normalizeVideoId(string $videoId): string
    {
        $videoId = trim($videoId);

        if (
            $videoId === ''
            || str_contains($videoId, '..')
            || str_contains($videoId, '/')
            || str_contains($videoId, '\\')
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}$/', $videoId)
        ) {
            throw new RuntimeException('Invalid video identifier.');
        }

        return preg_replace('/\.mp4$/i', '', $videoId) ?: $videoId;
    }

    private function normalizeVideoRole(mixed $videoRole): ?string
    {
        if (! is_string($videoRole) || trim($videoRole) === '') {
            return null;
        }

        $normalized = strtoupper(trim($videoRole));

        if (! in_array($normalized, ['IDENTITY', 'PREDICTION'], true)) {
            throw new RuntimeException('Invalid video role.');
        }

        return $normalized;
    }

    private function normalizeLanguage(mixed $language): string
    {
        if (! is_string($language) || trim($language) === '') {
            return 'ta';
        }

        $normalized = strtolower(trim($language));

        if (! in_array($normalized, ['ta', 'en', 'hi'], true)) {
            throw new RuntimeException('Invalid video language.');
        }

        return $normalized;
    }

    private function filenameResolutionForVideoId(string $videoId, ?string $videoRole = null, string $language = 'ta'): array
    {
        $filenames = $this->filenameCandidatesForVideoId($videoId, $videoRole, $language);
        $candidates = array_map(
            fn (string $filename, int $index): array => [
                'type' => $index === 0 ? 'padded' : 'raw',
                'filename' => $filename,
                'exists' => $this->absolutePathForFilename($filename) !== null,
            ],
            $filenames,
            array_keys($filenames)
        );

        $existingCandidate = collect($candidates)->first(fn (array $candidate): bool => $candidate['exists']);

        return [
            'filename' => $existingCandidate['filename'] ?? $candidates[0]['filename'],
            'candidates' => $candidates,
        ];
    }

    private function filenameForVideoId(string $videoId, ?string $videoRole = null, string $language = 'ta'): string
    {
        return $this->filenameCandidatesForVideoId($videoId, $videoRole, $language)[0];
    }

    private function filenameCandidatesForVideoId(string $videoId, ?string $videoRole = null, string $language = 'ta'): array
    {
        [$rawVideoId, $paddedVideoId] = $this->canonicalVideoNumberPair($this->normalizeVideoId($videoId));

        if ($videoRole === 'IDENTITY') {
            return $this->uniqueFilenames([
                match ($this->normalizeLanguage($language)) {
                    'en' => "{$paddedVideoId}_en-IN-dubbed_video_final.mp4",
                    'hi' => "{$paddedVideoId}_hi-IN-dubbed_video_final.mp4",
                    default => "{$paddedVideoId}_final.mp4",
                },
                match ($this->normalizeLanguage($language)) {
                    'en' => "{$rawVideoId}_en-IN-dubbed_video_final.mp4",
                    'hi' => "{$rawVideoId}_hi-IN-dubbed_video_final.mp4",
                    default => "{$rawVideoId}_final.mp4",
                },
            ]);
        }

        return $this->uniqueFilenames([
            match ($this->normalizeLanguage($language)) {
                'en' => "{$paddedVideoId}_en-IN-dubbed_video_final.mp4",
                'hi' => "{$paddedVideoId}_hi-IN-dubbed_video_final.mp4",
                default => "{$paddedVideoId}_final.mp4",
            },
            match ($this->normalizeLanguage($language)) {
                'en' => "{$rawVideoId}_en-IN-dubbed_video_final.mp4",
                'hi' => "{$rawVideoId}_hi-IN-dubbed_video_final.mp4",
                default => "{$rawVideoId}_final.mp4",
            },
        ]);
    }

    private function canonicalVideoNumberPair(string $videoId): array
    {
        $paddedVideoId = $this->canonicalFiveDigitVideoNumber($videoId);
        $rawVideoId = ltrim($paddedVideoId, '0');

        return [$rawVideoId === '' ? '0' : $rawVideoId, $paddedVideoId];
    }

    private function uniqueFilenames(array $filenames): array
    {
        return array_values(array_unique($filenames));
    }

    private function canonicalFiveDigitVideoNumber(string $videoId): string
    {
        $digits = preg_replace('/\D+/', '', $videoId) ?: '';

        if ($digits === '' || strlen($digits) > 5) {
            throw new RuntimeException('Invalid video number.');
        }

        return str_pad($digits, 5, '0', STR_PAD_LEFT);
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = trim($filename);

        if (
            $filename === ''
            || str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,170}\.mp4$/i', $filename)
        ) {
            throw new RuntimeException('Invalid video filename.');
        }

        return $filename;
    }
}

class ProtectedVideoPlaybackException extends RuntimeException
{
    public function __construct(string $message, private readonly array $debug = [], private readonly int $status = 403)
    {
        parent::__construct($message);
    }

    public function debug(): array
    {
        return $this->debug;
    }

    public function status(): int
    {
        return $this->status;
    }
}
