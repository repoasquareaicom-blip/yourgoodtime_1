<?php

namespace Tests\Unit;

use App\Services\Prediction\AstrologyNameMapper;
use App\Services\Prediction\PredictionExcelValidationService;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PredictionExcelValidationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->predictionDataDirectory());
        config(['prediction.excel_path' => $this->predictionDataDirectory()]);
    }

    public function test_rule002_past_workbook_validator_accepts_reusable_past_workbook_structure(): void
    {
        $this->createRule002PastWorkbook('16.xlsx');

        $result = app(PredictionExcelValidationService::class)->validateRule002PastWorkbooks('16.xlsx');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['valid']);
        $this->assertSame('rule002_past_workbooks', $result['validator']);
        $this->assertSame(1, $result['summary']['workbooks_checked']);
        $this->assertSame(9, $result['summary']['worksheets_checked']);
        $this->assertSame(1296, $result['summary']['total_rows']);
        $this->assertSame(1296, $result['summary']['valid_rows']);
        $this->assertSame(0, $result['summary']['issue_rows']);
        $this->assertSame([], $result['issues']);
    }

    public function test_rule002_past_workbook_validator_reports_missing_video_ids(): void
    {
        $this->createRule002PastWorkbook('16.xlsx', missingVideo: true);

        $result = app(PredictionExcelValidationService::class)->validateRule002PastWorkbooks('16.xlsx');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['summary']['missing_video_ids']);
        $this->assertSame('16.xlsx', $result['issues'][0]['workbook']);
        $this->assertSame('SUN', $result['issues'][0]['worksheet']);
        $this->assertSame('Video', $result['issues'][0]['field']);
    }

    public function test_rule002_past_workbook_validator_reports_overlapping_age_ranges(): void
    {
        $this->createRule002PastWorkbook('16 TO 20.xlsx');
        $this->createRule002PastWorkbook('20 TO 22.xlsx');

        $result = app(PredictionExcelValidationService::class)->validateRule002PastWorkbooks();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['summary']['overlapping_age_ranges']);
        $this->assertSame('Age range', collect($result['issues'])->firstWhere('field', 'Age range')['field']);
    }

    private function createRule002PastWorkbook(string $fileName, bool $missingVideo = false): void
    {
        $directory = $this->predictionDataDirectory().DIRECTORY_SEPARATOR.'past';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $spreadsheet = new Spreadsheet();
        $sheetNames = ['SUN', 'MOON', 'MARS', 'GURU', 'KETU', 'RAHU', 'SANI', 'BUDHAN', 'VENUS'];

        foreach ($sheetNames as $index => $sheetName) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);
            $sheet->fromArray($this->rule002Rows($sheetName, $missingVideo && $sheetName === 'SUN'));
        }

        (new Xlsx($spreadsheet))->save($directory.DIRECTORY_SEPARATOR.$fileName);
        $spreadsheet->disconnectWorksheets();
    }

    private function rule002Rows(string $sheetName, bool $missingVideo): array
    {
        $mapper = app(AstrologyNameMapper::class);
        $lagnams = array_values(array_unique(config('prediction.name_mappings.workbook_lagnams')));
        $rows = [
            [$sheetName.' DASA', 'POSITION'],
            ['LAGNAM', ''],
        ];

        foreach ($lagnams as $lagnamIndex => $lagnam) {
            for ($position = 1; $position <= 12; $position++) {
                $video = $missingVideo && $lagnamIndex === 0 && $position === 1 ? '' : 'VID_'.$position;
                $rows[] = [
                    $position === 1 ? $mapper->excelValue($lagnam, 'workbook_lagnams') : '',
                    (string) $position,
                    '2007',
                    $video,
                ];
            }
        }

        return $rows;
    }

    private function predictionDataDirectory(): string
    {
        return storage_path('framework/testing/prediction-excel-validation-data');
    }
}
