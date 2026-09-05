<?php

namespace App\Ai\Agents\SubAgents;

use App\Ai\Prompts\DocumentationPrompt;
use App\Ai\Providers\AiProviderContract;
use App\Support\ScoreCalculator;

/**
 * Sub-Agent بُعد التوثيق والبيانات — method: agent.documentation.evaluate.
 */
final class DocumentationSubAgent extends AbstractSubAgent
{
    public function __construct(AiProviderContract $provider, ScoreCalculator $calculator)
    {
        parent::__construct($provider, new DocumentationPrompt(), $calculator);
    }

    public function dimension(): string
    {
        return 'documentation';
    }
}
