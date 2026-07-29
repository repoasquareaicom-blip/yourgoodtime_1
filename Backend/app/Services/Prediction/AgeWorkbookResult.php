<?php

namespace App\Services\Prediction;

class AgeWorkbookResult
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $path,
        public readonly ?string $fileName,
        public readonly string $period,
        public readonly ?int $fromAge = null,
        public readonly ?int $toAge = null,
        public readonly ?int $supportedFromAge = null,
        public readonly ?int $supportedToAge = null,
        public readonly ?string $remarks = null
    ) {
    }
}
