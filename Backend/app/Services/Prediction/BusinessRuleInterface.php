<?php

namespace App\Services\Prediction;

interface BusinessRuleInterface
{
    public function execute(PredictionSession $session): RuleResult;
}
