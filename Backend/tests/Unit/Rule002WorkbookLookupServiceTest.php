<?php

namespace Tests\Unit;

use App\Services\Prediction\Rule002WorkbookLookupService;
use Tests\TestCase;

class Rule002WorkbookLookupServiceTest extends TestCase
{
    public function test_it_matches_past_lagnam_position_rows_with_rule002_fallback_rasi_behavior(): void
    {
        $lookup = app(Rule002WorkbookLookupService::class);

        $rows = [
            1 => ['A' => 'VENUS DASA', 'B' => 'POSITION'],
            2 => ['A' => 'LAGNAM', 'B' => ''],
            3 => ['A' => 'MESHAM', 'B' => ''],
            4 => ['A' => '', 'B' => '5', 'C' => '2014', 'D' => 'WRONG_POSITION'],
            5 => ['A' => '', 'B' => '6', 'C' => '2014', 'D' => 'PRED_2014'],
            6 => ['A' => 'RISHABAM', 'B' => ''],
            7 => ['A' => '', 'B' => '6', 'C' => '2014', 'D' => 'WRONG_LAGNAM'],
        ];

        $matches = $lookup->matchingRows($rows, 'Aries', 'Pisces', 6);

        $this->assertSame([5], array_keys($matches));
        $this->assertSame('MEENAM', $matches[5]['matched_rasi']);
    }

    public function test_it_matches_rasi_sections_when_the_lagnam_block_contains_them(): void
    {
        $lookup = app(Rule002WorkbookLookupService::class);

        $rows = [
            1 => ['A' => 'VENUS DASA', 'B' => 'POSITION'],
            2 => ['A' => 'LAGNAM', 'B' => ''],
            3 => ['A' => 'MESHAM', 'B' => ''],
            4 => ['A' => 'THULAM', 'B' => '6', 'C' => '2014', 'D' => 'WRONG_RASI'],
            5 => ['A' => 'MEENAM', 'B' => '6', 'C' => '2014', 'D' => 'RIGHT_RASI'],
        ];

        $matches = $lookup->matchingRows($rows, 'Aries', 'Pisces', 6);

        $this->assertSame([5], array_keys($matches));
        $this->assertSame('MEENAM', $matches[5]['matched_rasi']);
    }

    public function test_it_uses_rule002_year_and_video_parsing(): void
    {
        $lookup = app(Rule002WorkbookLookupService::class);

        $row = [
            'A' => 'MESHAM',
            'B' => '6',
            'C' => '2013-2014',
            'D' => 'A_6152, B_6153',
            'E' => 'A_6152',
            'F' => '2020',
            'G' => 'WRONG_YEAR',
        ];

        $this->assertTrue($lookup->cellContainsYear('2013-2014', 2014));
        $this->assertTrue($lookup->cellContainsAnyYear('2007-2008-2009'));
        $this->assertFalse($lookup->cellContainsAnyYear('FY 2007'));
        $this->assertSame([2013, 2014], $lookup->matchedYearRange('2013-2014'));

        $match = $lookup->findYearVideos($row, 2014);

        $this->assertSame('C', $match['year_column']);
        $this->assertSame('D', $match['first_video_column']);
        $this->assertSame('2013-2014', $match['matched_year']);
        $this->assertSame(['A_6152', 'B_6153'], $match['video_ids']);
    }
}
