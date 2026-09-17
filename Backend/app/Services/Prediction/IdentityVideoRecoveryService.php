<?php

namespace App\Services\Prediction;

use App\Models\PredictionRequest;
use App\Models\PredictionResult;
use App\Services\Prediction\Rules\Rule001NakshatraLagnam;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class IdentityVideoRecoveryService
{
    public function __construct(
        private readonly Rule001NakshatraLagnam $rule001,
        private readonly PredictionConfig $predictionConfig
    ) {
    }

    public function ensureIdentityVideo(PredictionResult $result): PredictionResult
    {
        $result->loadMissing('predictionRequest');

        return $this->ensureIdentityVideoForRequest($result->predictionRequest, $result);
    }

    public function ensureIdentityVideoForRequest(?PredictionRequest $predictionRequest, PredictionResult $result): PredictionResult
    {
        if ($predictionRequest !== null) {
            $result->setRelation('predictionRequest', $predictionRequest);
        }

        $payload = $result->result_payload ?? [];
        $payloadIdentity = $this->normalizeIdentityVideo(data_get($payload, 'identity_video'));

        if ($payloadIdentity !== null) {
            if (! filled($result->identity_video_id)) {
                $result->forceFill([
                    'identity_video_id' => $payloadIdentity['video_id'],
                ])->save();
            }

            return $result->refresh();
        }

        if (filled($result->identity_video_id)) {
            $payload['identity_video'] = $this->identityFromVideoId((string) $result->identity_video_id);
            $result->forceFill([
                'result_payload' => $payload,
            ])->save();

            return $result->refresh();
        }

        $recoveredIdentity = $this->recoverFromStoredAstrology($predictionRequest ?? $result->predictionRequest);

        if ($recoveredIdentity === null) {
            $this->logMissingIdentity($result, 'Rule001 could not recover an Identity Video from stored astrology.');

            return $result;
        }

        $payload['identity_video'] = $recoveredIdentity;
        $result->forceFill([
            'identity_video_id' => $recoveredIdentity['video_id'],
            'result_payload' => $payload,
        ])->save();

        return $result->refresh();
    }

    public function warnIfGeneratedResultMissingIdentity(PredictionRequest $predictionRequest, string $predictionType, ?PredictionResult $result, array $payload): void
    {
        if (! $this->payloadHasPredictionVideo($payload) || filled(data_get($payload, 'identity_video.video_id'))) {
            return;
        }

        Log::warning('Prediction result generated without Identity Video.', [
            'prediction_request_id' => $predictionRequest->id,
            'prediction_result_id' => $result?->id,
            'prediction_type' => $predictionType,
            'rule' => 'RULE-001',
            'reason' => 'Generated payload did not include identity_video.video_id.',
        ]);
    }

    private function recoverFromStoredAstrology(?PredictionRequest $predictionRequest): ?array
    {
        if ($predictionRequest === null) {
            return null;
        }

        $identity = $this->storedAstrologyIdentity($predictionRequest);

        if (! filled($identity['lagnam'] ?? null) || ! filled($identity['rasi'] ?? null) || ! filled($identity['nakshatra'] ?? null)) {
            return null;
        }

        $context = (new PredictionContext())
            ->setRequest([
                'id' => $predictionRequest->id,
                'uuid' => $predictionRequest->request_uuid,
                'prediction_type' => $predictionRequest->prediction_type,
                'status' => $predictionRequest->status,
                'name' => $predictionRequest->full_name,
            ])
            ->setIdentity($identity)
            ->setAstrologyResponse($predictionRequest->normalized_astrology_data ?? []);

        $session = new PredictionSession(
            requestId: $predictionRequest->id,
            predictionContext: $context,
            playlistBuilder: new PlaylistBuilder(),
            developerTrace: new DeveloperTrace(),
            predictionConfig: $this->predictionConfig,
            currentDate: $predictionRequest->submitted_at
                ? CarbonImmutable::instance($predictionRequest->submitted_at)
                : null
        );

        $ruleResult = $this->rule001->execute($session);
        $ruleData = $ruleResult->toArray();
        $playlist = $session->getPlaylistBuilder()->getPlaylist();
        $item = $playlist[0] ?? null;
        $videoId = is_array($item) ? trim((string) ($item['video_id'] ?? '')) : '';

        if (($ruleData['status'] ?? null) !== 'SUCCESS' || count($playlist) !== 1 || $videoId === '') {
            Log::warning('Rule001 Identity Video recovery did not find a valid match.', [
                'prediction_request_id' => $predictionRequest->id,
                'rule' => 'RULE-001',
                'status' => $ruleData['status'] ?? null,
                'reason' => $ruleData['remarks'] ?? null,
            ]);

            return null;
        }

        $source = is_array($item['source'] ?? null) ? $item['source'] : [];
        $matchedValues = is_array($source['matched_values'] ?? null) ? $source['matched_values'] : [];

        return [
            'video_id' => $videoId,
            'source' => [
                'file' => $ruleData['excel_file'] ?? config('prediction.files.nakshatra_lagnam', 'nakshatra_lagnam.xlsx'),
                'sheet' => $ruleData['worksheet'] ?? $matchedValues['sheet'] ?? null,
                'lagnam' => $matchedValues['lagnam'] ?? null,
                'rasi' => $matchedValues['rasi'] ?? null,
                'nakshatra' => $matchedValues['nakshatra'] ?? null,
                'match_position' => $matchedValues['position'] ?? null,
                'excel_row_number' => $ruleData['matched_row'] ?? $matchedValues['excel_row_number'] ?? null,
                'excel_column' => $ruleData['matched_column'] ?? null,
                'excel_cell' => $ruleData['matched_cell'] ?? null,
                'video' => $videoId,
                'lookup_values' => $source['identified_values'] ?? [],
                'status' => $ruleData['status'] ?? null,
                'warning' => null,
            ],
        ];
    }

    private function storedAstrologyIdentity(PredictionRequest $predictionRequest): array
    {
        $normalized = $predictionRequest->normalized_astrology_data;
        $identity = data_get($normalized, 'identity');

        if (! is_array($identity)) {
            $identity = data_get($normalized, 'value.identity', []);
        }

        return is_array($identity) ? $identity : [];
    }

    private function normalizeIdentityVideo(mixed $identity): ?array
    {
        if (! is_array($identity) || ! filled($identity['video_id'] ?? null)) {
            return null;
        }

        return array_merge($identity, [
            'video_id' => (string) $identity['video_id'],
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

    private function logMissingIdentity(PredictionResult $result, string $reason): void
    {
        if (! $this->payloadHasPredictionVideo($result->result_payload ?? [])) {
            return;
        }

        Log::warning('Prediction result is missing Identity Video.', [
            'prediction_request_id' => $result->prediction_request_id,
            'prediction_result_id' => $result->id,
            'prediction_type' => $result->prediction_type,
            'rule' => 'RULE-001',
            'reason' => $reason,
        ]);
    }
}
