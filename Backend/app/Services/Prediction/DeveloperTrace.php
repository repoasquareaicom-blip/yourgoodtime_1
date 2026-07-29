<?php

namespace App\Services\Prediction;

use Carbon\CarbonImmutable;

class DeveloperTrace
{
    private ?float $startedAtTimestamp = null;

    private ?float $finishedAtTimestamp = null;

    private ?string $startedAt = null;

    private ?string $finishedAt = null;

    /**
     * @var array<int, array{message: string, timestamp: string}>
     */
    private array $messages = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $ruleResults = [];

    public function start(): self
    {
        $this->startedAtTimestamp = microtime(true);
        $this->startedAt = CarbonImmutable::now()->toISOString();

        return $this;
    }

    public function addMessage(string $message): self
    {
        $this->messages[] = [
            'message' => $message,
            'timestamp' => CarbonImmutable::now()->toISOString(),
        ];

        return $this;
    }

    public function addRuleResult(RuleResult $ruleResult): self
    {
        $this->ruleResults[] = $ruleResult->toArray();

        return $this;
    }

    public function finish(): self
    {
        $this->finishedAtTimestamp = microtime(true);
        $this->finishedAt = CarbonImmutable::now()->toISOString();

        return $this;
    }

    public function toArray(): array
    {
        $durationMs = null;

        if ($this->startedAtTimestamp !== null && $this->finishedAtTimestamp !== null) {
            $durationMs = (int) round(($this->finishedAtTimestamp - $this->startedAtTimestamp) * 1000);
        }

        return [
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_ms' => $durationMs,
            'messages' => $this->messages,
            'rule_results' => $this->ruleResults,
        ];
    }
}
