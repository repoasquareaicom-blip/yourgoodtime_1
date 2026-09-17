<?php

namespace Tests\Unit;

use App\Services\Prediction\AstrologyNameMapper;
use Tests\TestCase;

class AstrologyNameMapperTest extends TestCase
{
    public function test_swati_aliases_normalize_to_swathi(): void
    {
        $mapper = app(AstrologyNameMapper::class);

        foreach (['Swati', 'swati', 'Svati', 'svati'] as $input) {
            $this->assertSame('Swathi', $mapper->excelValue($input, 'nakshatras'), $input);
            $this->assertSame('swathi', $mapper->comparisonValue($input, 'nakshatras'), $input);
        }

        $this->assertSame('Swathi', $mapper->excelValue('Swathi', 'nakshatras'));
        $this->assertSame('swathi', $mapper->comparisonValue('Swathi', 'nakshatras'));
        $this->assertSame('swathi', $mapper->comparisonValue('swathi', 'nakshatras'));
    }

    public function test_existing_nakshatra_aliases_remain_unchanged(): void
    {
        $mapper = app(AstrologyNameMapper::class);

        $this->assertSame('Uttaraphalguni', $mapper->excelValue('Uttra Phalguni', 'nakshatras'));
        $this->assertSame('Poorvaphalguni', $mapper->excelValue('Purva Phalguni', 'nakshatras'));
        $this->assertSame('Bharani', $mapper->excelValue('Bharani', 'nakshatras'));
        $this->assertSame('bharani', $mapper->comparisonValue('Bharani', 'nakshatras'));
        $this->assertSame('swathi', $mapper->comparisonValue('Swati', 'nakshatras'));
        $this->assertSame('swathi', $mapper->comparisonValue('Swathi', 'nakshatras'));
    }
}
