<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Prompts\TechnicalQualityPrompt;
use App\Ai\Providers\AiProviderContract;
use App\Support\ScoreCalculator;

/**
 * Sub-Agent بُعد الجودة التقنية — method: agent.technical_quality.evaluate.
 */
final class TechnicalQualitySubAgent extends AbstractSubAgent
{
    public function __construct(AiProviderContract $provider, ScoreCalculator $calculator)
    {
        parent::__construct($provider, new TechnicalQualityPrompt(), $calculator);
    }

    public function dimension(): string
    {
        return 'technical_quality';
    }
}
