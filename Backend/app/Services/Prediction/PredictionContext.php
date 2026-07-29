<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class PredictionContext
{
    private array $request = [];

    private array $birth = [];

    private array $identity = [];

    private array $planets = [];

    private array $charts = [];

    private array $dasha = [];

    private array $astrologyResponse = [];

    public function getRequest(): array
    {
        return $this->request;
    }

    public function setRequest(array $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getBirth(): array
    {
        return $this->birth;
    }

    public function getBirthDate(): ?CarbonImmutable
    {
        return $this->parseDate($this->birth['date_of_birth'] ?? null);
    }

    public function setBirth(array $birth): self
    {
        $this->birth = $birth;

        return $this;
    }

    public function getIdentity(): array
    {
        return $this->identity;
    }

    public function getLagnam(): ?string
    {
        return $this->nullableString($this->identity['lagnam'] ?? null);
    }

    public function getRasi(): ?string
    {
        return $this->nullableString($this->identity['rasi'] ?? null);
    }

    public function getNakshatra(): ?string
    {
        return $this->nullableString($this->identity['nakshatra'] ?? null);
    }

    public function setIdentity(array $identity): self
    {
        $this->identity = $identity;

        return $this;
    }

    public function getPlanets(): array
    {
        return $this->planets;
    }

    public function setPlanets(array $planets): self
    {
        $this->planets = $planets;

        return $this;
    }

    public function getCharts(): array
    {
        return $this->charts;
    }

    public function setCharts(array $charts): self
    {
        $this->charts = $charts;

        return $this;
    }

    public function getDasha(): array
    {
        return $this->dasha;
    }

    public function getCurrentMahadasha(): ?string
    {
        return $this->nullableString(
            $this->dasha['current']['major']['planet']
                ?? $this->dasha['current_major_lord']
                ?? null
        );
    }

    public function getCurrentMahadashaStartDate(): ?string
    {
        return $this->nullableString($this->dasha['current']['major']['start'] ?? null);
    }

    public function getCurrentMahadashaEndDate(): ?string
    {
        return $this->nullableString($this->dasha['current']['major']['end'] ?? null);
    }

    public function getMahadashaForDate(CarbonInterface $predictionDate): ?DashaPeriod
    {
        foreach (($this->dasha['major'] ?? []) as $period) {
            if (! is_array($period)) {
                continue;
            }

            $planet = $this->nullableString($period['planet'] ?? null);
            $start = $this->nullableString($period['start'] ?? null);
            $end = $this->nullableString($period['end'] ?? null);
            $startDate = $this->parseDate($start);
            $endDate = $this->parseDate($end);

            if ($planet === null || $start === null || $end === null || $startDate === null || $endDate === null) {
                continue;
            }

            if ($predictionDate->greaterThanOrEqualTo($startDate) && $predictionDate->lessThanOrEqualTo($endDate)) {
                return new DashaPeriod(
                    planet: $planet,
                    planetId: isset($period['planet_id']) ? (int) $period['planet_id'] : null,
                    startDate: $start,
                    endDate: $end
                );
            }
        }

        $current = $this->currentDashaPeriod();

        if ($current === null) {
            return null;
        }

        $startDate = $this->parseDate($current->startDate);
        $endDate = $this->parseDate($current->endDate);

        if ($startDate === null || $endDate === null) {
            return null;
        }

        return $predictionDate->greaterThanOrEqualTo($startDate) && $predictionDate->lessThanOrEqualTo($endDate)
            ? $current
            : null;
    }

    public function getMahadashaForSystemYear(int $systemYear): ?DashaPeriod
    {
        foreach (($this->dasha['major'] ?? []) as $period) {
            if (! is_array($period)) {
                continue;
            }

            $planet = $this->nullableString($period['planet'] ?? null);
            $start = $this->nullableString($period['start'] ?? null);
            $end = $this->nullableString($period['end'] ?? null);
            $startDate = $this->parseDate($start);
            $endDate = $this->parseDate($end);

            if ($planet === null || $start === null || $end === null || $startDate === null || $endDate === null) {
                continue;
            }

            if ($systemYear >= (int) $startDate->format('Y') && $systemYear <= (int) $endDate->format('Y')) {
                return new DashaPeriod(
                    planet: $planet,
                    planetId: isset($period['planet_id']) ? (int) $period['planet_id'] : null,
                    startDate: $start,
                    endDate: $end
                );
            }
        }

        return null;
    }

    public function getBirthYear(): ?int
    {
        $birthDate = $this->getBirthDate();

        return $birthDate === null ? null : (int) $birthDate->format('Y');
    }

    public function getPlanetHousePosition(string $planet): ?int
    {
        $normalizedPlanet = $this->baseNormalizedValue($planet);

        foreach ($this->planets as $planetRecord) {
            if (! is_array($planetRecord)) {
                continue;
            }

            $name = $this->baseNormalizedValue($planetRecord['name'] ?? null);

            if ($name === $normalizedPlanet && isset($planetRecord['house'])) {
                return (int) $planetRecord['house'];
            }
        }

        return null;
    }

    public function setDasha(array $dasha): self
    {
        $this->dasha = $dasha;

        return $this;
    }

    public function getAstrologyResponse(): array
    {
        return $this->astrologyResponse;
    }

    public function setAstrologyResponse(array $astrologyResponse): self
    {
        $this->astrologyResponse = $astrologyResponse;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'request' => $this->request,
            'birth' => $this->birth,
            'identity' => $this->identity,
            'planets' => $this->planets,
            'charts' => $this->charts,
            'dasha' => $this->dasha,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function currentDashaPeriod(): ?DashaPeriod
    {
        $planet = $this->getCurrentMahadasha();
        $start = $this->getCurrentMahadashaStartDate();
        $end = $this->getCurrentMahadashaEndDate();

        if ($planet === null || $start === null || $end === null) {
            return null;
        }

        return new DashaPeriod(
            planet: $planet,
            planetId: isset($this->dasha['current']['major']['planet_id']) ? (int) $this->dasha['current']['major']['planet_id'] : null,
            startDate: $start,
            endDate: $end
        );
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y H:i', 'j-n-Y H:i', 'd-m-Y', 'j-n-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, preg_replace('/\s+/', ' ', trim($value)));

                if ($date !== false) {
                    return str_contains($format, 'H') ? $date : $date->startOfDay();
                }
            } catch (Throwable) {
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function baseNormalizedValue(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return preg_replace('/\s+/', ' ', $normalized) ?: '';
    }
}
