<?php

namespace App\Services\Prediction\Rules;

use App\Services\Prediction\AstrologyNameMapper;
use App\Services\Prediction\BusinessRuleInterface;
use App\Services\Prediction\Excel\ExcelReader;
use App\Services\Prediction\PredictionConfig;
use App\Services\Prediction\PredictionSession;
use App\Services\Prediction\RuleResult;
use Throwable;

class Rule001NakshatraLagnam implements BusinessRuleInterface
{
    private const RULE_CODE = 'RULE-001';

    private const RULE_NAME = 'Nakshatra Lagnam Introduction';

    public function __construct(
        private readonly ExcelReader $excelReader,
        private readonly PredictionConfig $predictionConfig,
        private readonly AstrologyNameMapper $nameMapper
    ) {
    }

    public function execute(PredictionSession $session): RuleResult
    {
        $startedAt = microtime(true);
        $lookupValues = $this->lookupValues($session);
        $excelFile = $this->excelFile();

        try {
            $workbook = $this->excelReader->readFirstWorksheet($this->excelPath());
            $worksheet = $workbook['worksheet'];
            $rows = $workbook['rows'];

            $headers = $this->headers($rows);
            $requiredColumns = $this->requiredColumns($headers);

            if ($requiredColumns === null) {
                return $this->failed($startedAt, $excelFile, $worksheet, $lookupValues, 'Required columns not found.');
            }

            foreach ($rows as $rowNumber => $row) {
                if ((int) $rowNumber === 1 || ! $this->rowMatches($row, $requiredColumns, $lookupValues)) {
                    continue;
                }

                $videoId = trim((string) ($row[$requiredColumns['video_id']] ?? ''));

                if ($videoId === '') {
                    return $this->failed(
                        $startedAt,
                        $excelFile,
                        $worksheet,
                        $lookupValues,
                        'Matching row found without VideoId.',
                        (int) $rowNumber,
                        $requiredColumns['video_id'],
                        $requiredColumns['video_id'].$rowNumber
                    );
                }

                $matchedValues = array_merge($this->matchedValues($lookupValues), [
                    'excel_row_number' => (int) $rowNumber,
                    'sheet' => $worksheet,
                ]);

                $session->getPlaylistBuilder()->addVideo(
                    $videoId,
                    self::RULE_CODE,
                    self::RULE_NAME,
                    $lookupValues,
                    $matchedValues
                );

                return new RuleResult(
                    ruleCode: self::RULE_CODE,
                    ruleName: self::RULE_NAME,
                    status: 'SUCCESS',
                    executionTimeMs: $this->executionTimeMs($startedAt),
                    excelFile: $excelFile,
                    worksheet: $worksheet,
                    lookupValues: $lookupValues,
                    matchedValues: $matchedValues,
                    matchedRow: (int) $rowNumber,
                    matchedColumn: $requiredColumns['video_id'],
                    matchedCell: $requiredColumns['video_id'].$rowNumber,
                    resultValue: $videoId,
                    remarks: 'Matching row found.'
                );
            }

            return $this->failed($startedAt, $excelFile, $worksheet, $lookupValues, 'No matching row found.');
        } catch (Throwable $exception) {
            return $this->failed($startedAt, $excelFile, null, $lookupValues, $exception->getMessage());
        }
    }

    private function lookupValues(PredictionSession $session): array
    {
        $context = $session->getPredictionContext();

        return [
            'lagnam' => $context->getLagnam(),
            'rasi' => $context->getRasi(),
            'nakshatra' => $context->getNakshatra(),
        ];
    }

    private function excelPath(): string
    {
        return rtrim($this->predictionConfig->excelPath(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$this->excelFile();
    }

    private function excelFile(): string
    {
        return $this->predictionConfig->predictionFile('nakshatra_lagnam');
    }

    private function headers(array $rows): array
    {
        $headers = [];

        foreach (($rows[1] ?? []) as $column => $header) {
            $normalized = $this->nameMapper->headerValue($header);

            if ($normalized !== '') {
                $headers[$normalized] = $column;
            }
        }

        return $headers;
    }

    private function requiredColumns(array $headers): ?array
    {
        $columns = [
            'lagnam' => $headers['lagnam'] ?? null,
            'rasi' => $headers['rasi'] ?? null,
            'nakshatra' => $headers['nakshatra'] ?? null,
            'video_id' => $headers['videoid'] ?? $headers['video_id'] ?? $headers['video'] ?? null,
        ];

        return in_array(null, $columns, true) ? null : $columns;
    }

    private function rowMatches(array $row, array $columns, array $lookupValues): bool
    {
        return $this->matches($row[$columns['lagnam']] ?? null, $lookupValues['lagnam'], 'signs')
            && $this->matches($row[$columns['rasi']] ?? null, $lookupValues['rasi'], 'signs')
            && $this->matches($row[$columns['nakshatra']] ?? null, $lookupValues['nakshatra'], 'nakshatras');
    }

    private function matches(mixed $actual, mixed $expected, string $domain): bool
    {
        return $this->nameMapper->comparisonValue($actual, $domain) === $this->nameMapper->comparisonValue($expected, $domain);
    }

    private function matchedValues(array $lookupValues): array
    {
        return [
            'lagnam' => $this->nameMapper->excelValue($lookupValues['lagnam'] ?? null, 'signs'),
            'rasi' => $this->nameMapper->excelValue($lookupValues['rasi'] ?? null, 'signs'),
            'nakshatra' => $this->nameMapper->excelValue($lookupValues['nakshatra'] ?? null, 'nakshatras'),
        ];
    }

    private function failed(
        float $startedAt,
        ?string $excelFile,
        ?string $worksheet,
        array $lookupValues,
        string $remarks,
        null|int|string $matchedRow = null,
        null|int|string $matchedColumn = null,
        null|int|string $matchedCell = null,
        mixed $resultValue = null
    ): RuleResult
    {
        return new RuleResult(
            ruleCode: self::RULE_CODE,
            ruleName: self::RULE_NAME,
            status: 'FAILED',
            executionTimeMs: $this->executionTimeMs($startedAt),
            excelFile: $excelFile,
            worksheet: $worksheet,
            lookupValues: $lookupValues,
            matchedValues: $this->matchedValues($lookupValues),
            matchedRow: $matchedRow,
            matchedColumn: $matchedColumn,
            matchedCell: $matchedCell,
            resultValue: $resultValue,
            remarks: $remarks
        );
    }

    private function executionTimeMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
