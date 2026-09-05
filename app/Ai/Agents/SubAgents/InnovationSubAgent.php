<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Prompts\InnovationPrompt;
use App\Ai\Providers\AiProviderContract;
use App\Support\ScoreCalculator;

/**
 * Sub-Agent بُعد الإبداع والتميز — method: agent.innovation.evaluate.
 */
final class InnovationSubAgent extends AbstractSubAgent
{
    public function __construct(AiProviderContract $provider, ScoreCalculator $calculator)
    {
        parent::__construct($provider, new InnovationPrompt(), $calculator);
    }

    public function dimension(): string
    {
        return 'innovation';
    }
}
