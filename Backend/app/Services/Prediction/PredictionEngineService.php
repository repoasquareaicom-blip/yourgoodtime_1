<?php

namespace App\Services\Prediction;

use App\Models\PredictionRequest;
use App\Services\Prediction\Rules\Rule001NakshatraLagnam;
use App\Services\Prediction\Rules\Rule002CurrentMahadasha;
use Carbon\CarbonImmutable;
use Throwable;

class PredictionEngineService
{
    public function __construct(
        private readonly PredictionConfig $predictionConfig,
        private readonly RuleEngine $ruleEngine,
        private readonly Rule001NakshatraLagnam $rule001,
        private readonly Rule002CurrentMahadasha $rule002,
        private readonly PredictionTimelineGenerator $timelineGenerator,
        private readonly PredictionRuleLogService $ruleLogService
    ) {
    }

    public function preparePastPrediction(int $requestId): PredictionSession
    {
        $trace = (new DeveloperTrace())->start();

        $predictionRequest = PredictionRequest::query()->findOrFail($requestId);
        $trace->addMessage('Prediction request loaded.');

        $normalizedPayload = $this->decodePayload($predictionRequest->normalized_astrology_data);
        $rawPayload = $this->decodePayload($predictionRequest->raw_astrology_response);
        $astrologyPayload = $normalizedPayload !== [] ? $normalizedPayload : $rawPayload;
        $trace->addMessage('Stored astrology response decoded.');

        $context = $this->buildContext($predictionRequest, $astrologyPayload);
        $trace->addMessage('PredictionContext created.');

        $session = new PredictionSession(
            requestId: $predictionRequest->id,
            predictionContext: $context,
            playlistBuilder: new PlaylistBuilder(),
            developerTrace: $trace,
            predictionConfig: $this->predictionConfig,
            currentDate: $this->predictionDate($predictionRequest)
        );
        $trace->addMessage('PredictionSession created.');

        $this->ruleEngine->execute($session);
        $trace->finish();

        return $session;
    }

    public function preparePredictionTimeline(int $requestId, string $predictionType): PredictionTimeline
    {
        $trace = (new DeveloperTrace())->start();

        $predictionRequest = PredictionRequest::query()->findOrFail($requestId);
        $trace->addMessage('Prediction request loaded.');

        $normalizedPayload = $this->decodePayload($predictionRequest->normalized_astrology_data);
        $rawPayload = $this->decodePayload($predictionRequest->raw_astrology_response);
        $astrologyPayload = $normalizedPayload !== [] ? $normalizedPayload : $rawPayload;
        $trace->addMessage('Stored astrology response decoded.');

        $context = $this->buildContext($predictionRequest, $astrologyPayload, $predictionType);
        $trace->addMessage('PredictionContext created.');

        $predictionDate = $this->predictionDate($predictionRequest);
        $timelineData = $this->timelineGenerator->generate($predictionType, $predictionDate, $context->getBirthDate());

        $introSession = $this->newSession($predictionRequest->id, $context, $trace, $predictionDate);
        $ruleResults = [];

        $introResult = $this->executeRule($introSession, $this->rule001);
        $ruleResults[] = $introResult;
        $this->logIfFailed($predictionRequest->id, $predictionType, null, $introResult);

        $yearlyPredictions = [];

        foreach ($timelineData['year_sessions'] as $yearSession) {
            $session = $this->newSession($predictionRequest->id, $context, $trace, $yearSession->targetDate);
            $result = $this->executeRule($session, $this->rule002);
            $ruleResults[] = $result;
            $this->logIfFailed($predictionRequest->id, $predictionType, $yearSession, $result);

            $playlistItems = $session->getPlaylistBuilder()->getPlaylist();

            $yearlyPredictions[] = [
                'year' => $yearSession->targetYear,
                'age' => $yearSession->age,
                'status' => $this->publicYearStatus($playlistItems, [$result]),
                'display_message' => $playlistItems === [] ? 'Prediction is currently unavailable for this year.' : null,
                'playlist_items' => $playlistItems,
                'rule_results' => [$result],
            ];
        }

        $trace->finish();

        return new PredictionTimeline(
            predictionRequest: $predictionRequest,
            predictionType: $predictionType,
            effectiveYear: $timelineData['effective_year'],
            fromYear: $timelineData['from_year'],
            toYear: $timelineData['to_year'],
            configuredYears: $timelineData['configured_years'],
            candidateYears: $timelineData['candidate_years'],
            processedYears: $timelineData['processed_years'],
            excludedAgeCount: $timelineData['excluded_age_count'],
            minimumSupportedAge: $timelineData['minimum_supported_age'],
            maximumSupportedAge: $timelineData['maximum_supported_age'],
            status: $timelineData['status'],
            yearSessions: $timelineData['year_sessions'],
            introPlaylist: $introSession->getPlaylistBuilder()->getPlaylist(),
            yearlyPredictions: $yearlyPredictions,
            ruleResults: $ruleResults,
            developerTrace: $trace
        );
    }

    private function buildContext(PredictionRequest $predictionRequest, array $astrologyPayload, ?string $predictionType = null): PredictionContext
    {
        $birth = $this->arrayValue(data_get($astrologyPayload, 'birth'));
        $identity = $this->arrayValue(data_get($astrologyPayload, 'identity'));
        $dasha = $this->arrayValue(data_get($astrologyPayload, 'dasha'));
        $charts = $this->arrayValue(data_get($astrologyPayload, 'chart'));
        $planets = $this->arrayValue(data_get($astrologyPayload, 'planets'));

        return (new PredictionContext())
            ->setRequest([
                'id' => $predictionRequest->id,
                'uuid' => $predictionRequest->request_uuid,
                'prediction_type' => $predictionType ?? $predictionRequest->prediction_type,
                'status' => $predictionRequest->status,
                'name' => $predictionRequest->full_name,
            ])
            ->setBirth([
                'date_of_birth' => $this->firstValue(
                    data_get($birth, 'date_of_birth'),
                    optional($predictionRequest->date_of_birth)->format('Y-m-d')
                ),
                'time_of_birth' => $this->firstValue(
                    data_get($birth, 'time_of_birth'),
                    $predictionRequest->time_of_birth
                ),
                'place_of_birth' => $this->firstValue(
                    data_get($birth, 'place_of_birth'),
                    $predictionRequest->place_of_birth
                ),
                'latitude' => $this->nullableFloat($this->firstValue(
                    data_get($birth, 'latitude'),
                    $predictionRequest->latitude
                )),
                'longitude' => $this->nullableFloat($this->firstValue(
                    data_get($birth, 'longitude'),
                    $predictionRequest->longitude
                )),
            ])
            ->setIdentity([
                'lagnam' => $this->nullableString(data_get($identity, 'lagnam')),
                'rasi' => $this->nullableString(data_get($identity, 'rasi')),
                'nakshatra' => $this->nullableString(data_get($identity, 'nakshatra')),
                'lagnam_lord' => $this->nullableString(data_get($identity, 'lagnam_lord')),
                'rasi_lord' => $this->nullableString(data_get($identity, 'rasi_lord')),
                'nakshatra_lord' => $this->nullableString(data_get($identity, 'nakshatra_lord')),
                'nakshatra_pada' => data_get($identity, 'nakshatra_pada'),
            ])
            ->setDasha([
                'current_major_lord' => $this->nullableString(data_get($dasha, 'current_major_lord')),
                'current_minor_lord' => $this->nullableString(data_get($dasha, 'current_minor_lord')),
                'current' => $this->arrayValue(data_get($dasha, 'current')),
                'major' => $this->arrayValue(data_get($dasha, 'major')),
            ])
            ->setPlanets($planets)
            ->setCharts($charts)
            ->setAstrologyResponse($astrologyPayload);
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function firstValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function predictionDate(PredictionRequest $predictionRequest): CarbonImmutable
    {
        $value = $predictionRequest->completed_at
            ?? $predictionRequest->submitted_at
            ?? $predictionRequest->created_at;

        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::now();
    }

    private function newSession(int $requestId, PredictionContext $context, DeveloperTrace $trace, CarbonImmutable $predictionDate): PredictionSession
    {
        return new PredictionSession(
            requestId: $requestId,
            predictionContext: $context,
            playlistBuilder: new PlaylistBuilder(),
            developerTrace: $trace,
            predictionConfig: $this->predictionConfig,
            currentDate: $predictionDate
        );
    }

    private function executeRule(PredictionSession $session, BusinessRuleInterface $rule): RuleResult
    {
        $startedAt = microtime(true);

        try {
            $result = $rule->execute($session);
        } catch (Throwable $exception) {
            $result = new RuleResult(
                ruleCode: 'UNKNOWN',
                ruleName: $rule::class,
                status: 'ERROR',
                executionTimeMs: (int) round((microtime(true) - $startedAt) * 1000),
                remarks: $exception->getMessage()
            );
        }

        $session->addRuleResult($result);
        $session->getDeveloperTrace()->addRuleResult($result);
        $session->getDeveloperTrace()->addMessage(sprintf(
            'Rule executed: %s in %dms.',
            $result->toArray()['rule_code'],
            (int) round((microtime(true) - $startedAt) * 1000)
        ));

        return $result;
    }

    private function logIfFailed(int $requestId, string $predictionType, ?PredictionYearSession $yearSession, RuleResult $result): void
    {
        if (($result->toArray()['status'] ?? null) === 'SUCCESS') {
            return;
        }

        $this->ruleLogService->recordFailure($requestId, $predictionType, $yearSession, $result);
    }

    /**
     * @param array<int, mixed> $playlistItems
     * @param array<int, RuleResult> $ruleResults
     */
    private function publicYearStatus(array $playlistItems, array $ruleResults): string
    {
        $hasSuccess = $playlistItems !== [];
        $hasFailure = collect($ruleResults)->contains(fn (RuleResult $result): bool => ($result->toArray()['status'] ?? null) !== 'SUCCESS');

        if ($hasSuccess && $hasFailure) {
            return 'PARTIAL';
        }

        return $hasSuccess ? 'SUCCESS' : 'UNAVAILABLE';
    }
}
