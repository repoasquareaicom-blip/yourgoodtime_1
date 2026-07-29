<?php

namespace App\Services\Prediction;

class AstrologyNameMapper
{
    public function excelValue(mixed $value, string $domain): string
    {
        $normalized = $this->baseValue($value);
        $mapping = config("prediction.name_mappings.{$domain}", []);

        return is_array($mapping) && array_key_exists($normalized, $mapping)
            ? (string) $mapping[$normalized]
            : trim((string) $value);
    }

    public function comparisonValue(mixed $value, string $domain): string
    {
        return $this->baseValue($this->excelValue($value, $domain));
    }

    public function headerValue(mixed $value): string
    {
        return str_replace([' ', '-'], '_', $this->baseValue($value));
    }

    public function planetId(mixed $value): ?int
    {
        $normalized = $this->baseValue($value);
        $mapping = config('prediction.name_mappings.planet_ids', []);

        return is_array($mapping) && array_key_exists($normalized, $mapping)
            ? (int) $mapping[$normalized]
            : null;
    }

    private function baseValue(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return preg_replace('/\s+/', ' ', $normalized) ?: '';
    }
}
