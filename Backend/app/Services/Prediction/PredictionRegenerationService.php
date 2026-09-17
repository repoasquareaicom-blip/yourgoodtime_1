<?php

namespace App\Services\Prediction;

use App\Models\PredictionEntitlement;
use App\Models\PredictionRegenerationSetting;
use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PredictionRegenerationService
{
    public function __construct(
        private readonly PredictionEngineService $predictionEngineService,
        private readonly PredictionResponseBuilder $predictionResponseBuilder,
        private readonly IdentityVideoRecoveryService $identityVideoRecoveryService
    ) {
    }

    public function settings(): array
    {
        return collect(['past', 'future'])
            ->map(fn (string $type): array => $this->settingPayload($this->settingFor($type)))
            ->values()
            ->all();
    }

    public function markAll(string $predictionType, ?int $adminId = null): PredictionRegenerationSetting
    {
        $setting = DB::transaction(function () use ($predictionType, $adminId): PredictionRegenerationSetting {
            $setting = PredictionRegenerationSetting::query()
                ->where('prediction_type', $predictionType)
                ->lockForUpdate()
                ->first();

            if ($setting === null) {
                $setting = new PredictionRegenerationSetting([
                    'prediction_type' => $predictionType,
                    'regeneration_version' => 0,
                ]);
            }

            $setting->forceFill([
                'regeneration_version' => ((int) $setting->regeneration_version) + 1,
                'is_enabled' => true,
                'updated_by' => $adminId,
            ])->save();

            return $setting;
        });

        Log::info('Prediction regeneration cycle marked.', [
            'prediction_type' => $predictionType,
            'regeneration_version' => $setting->regeneration_version,
            'admin_id' => $adminId,
        ]);

        return $setting;
    }

    public function settingFor(string $predictionType): PredictionRegenerationSetting
    {
        return PredictionRegenerationSetting::query()->firstOrCreate(
            ['prediction_type' => $predictionType],
            ['regeneration_version' => 0, 'is_enabled' => false]
        );
    }

    public function resultIsStaleForGlobalCycle(PredictionResult $result, string $predictionType): bool
    {
        $setting = $this->settingFor($predictionType);

        return $setting->is_enabled
            && (int) $result->regeneration_version < (int) $setting->regeneration_version;
    }

    public function currentVersion(string $predictionType): int
    {
        return (int) $this->settingFor($predictionType)->regeneration_version;
    }

    public function regenerate(
        PredictionRequest $predictionRequest,
        string $predictionType,
        ?PredictionEntitlement $entitlement = null,
        ?int $targetVersion = null,
        bool $force = false
    ): PredictionResult {
        $targetVersion ??= $this->currentVersion($predictionType);
        $lockKey = 'prediction-result-regeneration:'.$predictionType.':'.$predictionRequest->id.':'.($entitlement?->id ?? 'none');

        return Cache::lock($lockKey, 120)->block(10, function () use ($predictionRequest, $predictionType, $entitlement, $targetVersion, $force): PredictionResult {
            $existing = $this->completedResult($predictionRequest, $predictionType, $entitlement);

            if (! $force && $existing !== null && (int) $existing->regeneration_version >= $targetVersion) {
                return $existing;
            }

            try {
                $timeline = $this->predictionEngineService->preparePredictionTimeline(
                    $predictionRequest->id,
                    $predictionType,
                    $entitlement
                );
                $result = $this->predictionResponseBuilder->buildTimeline($timeline);
                $payload = $this->payloadFromBuiltResult($result, $predictionRequest, $predictionType, $existing);

                if ($existing !== null && ! $this->payloadHasPredictionVideo($payload)) {
                    throw new RuntimeException('No matching prediction video is available for these birth details.');
                }

                $savedResult = PredictionResult::query()->updateOrCreate(
                    $this->resultIdentity($predictionRequest, $predictionType, $entitlement),
                    [
                        'user_id' => $entitlement?->user_id ?? $this->userIdForPredictionRequest($predictionRequest),
                        'prediction_entitlement_id' => $entitlement?->id,
                        'identity_video_id' => data_get($payload, 'identity_video.video_id'),
                        'result_payload' => $payload,
                        'summary_payload' => $payload['summary'] ?? [],
                        'warnings_payload' => $payload['warnings'] ?? [],
                        'status' => 'completed',
                        'regeneration_version' => $targetVersion,
                        'generated_at' => now(),
                    ]
                );

                $savedResult = $this->identityVideoRecoveryService->ensureIdentityVideo($savedResult);
                $this->identityVideoRecoveryService->warnIfGeneratedResultMissingIdentity($predictionRequest, $predictionType, $savedResult, $savedResult->result_payload ?? $payload);

                return $savedResult;
            } catch (Throwable $exception) {
                Log::error('Prediction regeneration failed.', [
                    'prediction_request_id' => $predictionRequest->id,
                    'prediction_type' => $predictionType,
                    'entitlement_id' => $entitlement?->id,
                    'target_regeneration_version' => $targetVersion,
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        });
    }

    public function settingPayload(PredictionRegenerationSetting $setting): array
    {
        return [
            'prediction_type' => $setting->prediction_type,
            'regeneration_version' => (int) $setting->regeneration_version,
            'is_enabled' => (bool) $setting->is_enabled,
            'updated_by' => $setting->updated_by,
            'updated_at' => $setting->updated_at?->toJSON(),
        ];
    }

    private function completedResult(PredictionRequest $predictionRequest, string $predictionType, ?PredictionEntitlement $entitlement): ?PredictionResult
    {
        $query = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('prediction_type', $predictionType)
            ->where('status', 'completed');

        if ($predictionType === 'future' && $entitlement !== null) {
            $query->where('prediction_entitlement_id', $entitlement->id);
        }

        return $query->first();
    }

    private function resultIdentity(PredictionRequest $predictionRequest, string $predictionType, ?PredictionEntitlement $entitlement): array
    {
        $identity = [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_type' => $predictionType,
        ];

        if ($predictionType === 'future' && $entitlement !== null) {
            $identity['prediction_entitlement_id'] = $entitlement->id;
        }

        return $identity;
    }

    private function payloadFromBuiltResult(array $result, PredictionRequest $predictionRequest, string $predictionType, ?PredictionResult $existing): array
    {
        $predictionVideos = $result['prediction_videos'] ?? [];
        $identityVideo = $this->authoritativeIdentityVideo($predictionRequest, $existing, $result['identity_video'] ?? null);

        return [
            'prediction_request_id' => $result['prediction_request_id'] ?? $predictionRequest->id,
            'type' => $result['prediction_type'] ?? $predictionType,
            'identity_video' => $identityVideo,
            'prediction_videos' => $predictionVideos,
            'summary' => $result['summary'] ?? [],
            'warnings' => $this->warnings($predictionVideos),
        ];
    }

    private function authoritativeIdentityVideo(PredictionRequest $predictionRequest, ?PredictionResult $existing, mixed $generatedIdentity): ?array
    {
        $identity = $this->identityFromResult($existing);

        if ($identity !== null) {
            return $identity;
        }

        $requestIdentityResult = PredictionResult::query()
            ->where('prediction_request_id', $predictionRequest->id)
            ->where('status', 'completed')
            ->where(function ($query): void {
                $query->whereNotNull('identity_video_id')
                    ->orWhereNotNull('result_payload');
            })
            ->latest('generated_at')
            ->latest('id')
            ->get()
            ->first(fn (PredictionResult $result): bool => $this->identityFromResult($result) !== null);

        $identity = $this->identityFromResult($requestIdentityResult);

        if ($identity !== null) {
            return $identity;
        }

        return $this->normalizeIdentityVideo($generatedIdentity);
    }

    private function identityFromResult(?PredictionResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        $identity = $this->normalizeIdentityVideo(data_get($result->result_payload ?? [], 'identity_video'));

        if ($identity !== null) {
            return $identity;
        }

        if (filled($result->identity_video_id)) {
            return $this->identityFromVideoId((string) $result->identity_video_id);
        }

        return null;
    }

    private function normalizeIdentityVideo(mixed $identity): ?array
    {
        if (! is_array($identity) || ! filled($identity['video_id'] ?? null)) {
            return null;
        }

        $source = is_array($identity['source'] ?? null) ? $identity['source'] : [];

        return array_merge($identity, [
            'video_id' => (string) $identity['video_id'],
            'source' => array_merge([
                'file' => config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'),
            ], $source),
        ]);
    }

    private function identityFromVideoId(string $videoId): array
    {
        return [
            'video_id' => $videoId,
            'source' => [
                'file' => config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'),
            ],
        ];
    }

    private function warnings(array $predictionVideos): array
    {
        return array_values(array_filter(array_map(
            fn (array $video): ?array => empty($video['warning'])
                ? null
                : [
                    'age' => $video['age'] ?? null,
                    'system_year' => $video['system_year'] ?? null,
                    'warning' => $video['warning'],
                ],
            $predictionVideos
        )));
    }

    private function payloadHasPredictionVideo(array $payload): bool
    {
        foreach ((array) data_get($payload, 'prediction_videos', []) as $videoGroup) {
            $videoIds = data_get($videoGroup, 'video_ids', []);

            if (is_array($videoIds) && count(array_filter($videoIds, fn (mixed $videoId): bool => filled($videoId))) > 0) {
                return true;
            }
        }

        return false;
    }

    private function userIdForPredictionRequest(PredictionRequest $predictionRequest): ?int
    {
        if (! filled($predictionRequest->email)) {
            return null;
        }

        return \App\Models\User::query()
            ->where('email', $predictionRequest->email)
            ->value('id');
    }
}
