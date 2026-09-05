<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Prompts\MarketViabilityPrompt;
use App\Ai\Providers\AiProviderContract;
use App\Support\ScoreCalculator;

/**
 * Sub-Agent بُعد الجدوى السوقية — method: agent.market_viability.evaluate.
 */
final class MarketViabilitySubAgent extends AbstractSubAgent
{
    public function __construct(AiProviderContract $provider, ScoreCalculator $calculator)
    {
        parent::__construct($provider, new MarketViabilityPrompt(), $calculator);
    }

    public function dimension(): string
    {
        return 'market_viability';
    }
}
