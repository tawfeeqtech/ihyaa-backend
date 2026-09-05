<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Prompts\TeamCompletenessPrompt;
use App\Ai\Providers\AiProviderContract;
use App\Support\ScoreCalculator;

/**
 * Sub-Agent بُعد اكتمال الفريق — method: agent.team_completeness.evaluate.
 */
final class TeamCompletenessSubAgent extends AbstractSubAgent
{
    public function __construct(AiProviderContract $provider, ScoreCalculator $calculator)
    {
        parent::__construct($provider, new TeamCompletenessPrompt(), $calculator);
    }

    public function dimension(): string
    {
        return 'team_completeness';
    }
}
