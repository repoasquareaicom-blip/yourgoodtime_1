<?php

namespace App\Services\Prediction;

class RuleEngine
{
    /**
     * @var array<int, BusinessRuleInterface>
     */
    private array $rules = [];

    public function addRule(BusinessRuleInterface $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * @param array<int, BusinessRuleInterface> $rules
     */
    public function setRules(array $rules): self
    {
        $this->rules = [];

        foreach ($rules as $rule) {
            $this->addRule($rule);
        }

        return $this;
    }

    public function execute(PredictionSession $session): PlaylistBuilder
    {
        $session->getDeveloperTrace()->addMessage('RuleEngine started.');

        foreach ($this->rules as $rule) {
            $startedAt = microtime(true);
            $result = $rule->execute($session);
            $session->addRuleResult($result);
            $session->getDeveloperTrace()->addRuleResult($result);

            $session->getDeveloperTrace()->addMessage(sprintf(
                'Rule executed: %s in %dms.',
                $result->toArray()['rule_code'],
                (int) round((microtime(true) - $startedAt) * 1000)
            ));
        }

        $session->getDeveloperTrace()->addMessage('RuleEngine finished.');

        return $session->getPlaylistBuilder();
    }
}
