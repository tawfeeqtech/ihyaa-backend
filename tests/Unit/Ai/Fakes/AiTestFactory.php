<?php

namespace Tests\Unit\Ai\Fakes;

use App\Ai\Agents\ConsensusAgent;
use App\Ai\Agents\EvaluationOrchestrator;
use App\Ai\Agents\SubAgents\DocumentationSubAgent;
use App\Ai\Agents\SubAgents\InnovationSubAgent;
use App\Ai\Agents\SubAgents\MarketViabilitySubAgent;
use App\Ai\Agents\SubAgents\SubAgentContract;
use App\Ai\Agents\SubAgents\TeamCompletenessSubAgent;
use App\Ai\Agents\SubAgents\TechnicalQualitySubAgent;
use App\Ai\Mcp\McpRouter;
use App\Ai\Prompts\DocumentationPrompt;
use App\Ai\Prompts\InnovationPrompt;
use App\Ai\Prompts\MarketViabilityPrompt;
use App\Ai\Prompts\PromptsContract;
use App\Ai\Prompts\TechnicalQualityPrompt;
use App\Ai\Prompts\TeamCompletenessPrompt;
use App\Ai\Validation\EvaluationOutputValidator;
use App\Support\ScoreCalculator;

/**
 * مصنع اختبارات لتركيب Sub-Agents / McpRouter / Orchestrator بمزوّدات وهمية.
 */
class AiTestFactory
{
    public const DIMENSIONS = [
        'technical_quality' => TechnicalQualitySubAgent::class,
        'innovation' => InnovationSubAgent::class,
        'market_viability' => MarketViabilitySubAgent::class,
        'team_completeness' => TeamCompletenessSubAgent::class,
        'documentation' => DocumentationSubAgent::class,
    ];

    public const PROMPTS = [
        'technical_quality' => TechnicalQualityPrompt::class,
        'innovation' => InnovationPrompt::class,
        'market_viability' => MarketViabilityPrompt::class,
        'team_completeness' => TeamCompletenessPrompt::class,
        'documentation' => DocumentationPrompt::class,
    ];

    /**
     * بناء Sub-Agent بُعد بمزوّد وهمي.
     */
    public static function subAgent(string $dimension, FakeAiProvider $provider): SubAgentContract
    {
        $class = self::DIMENSIONS[$dimension] ?? throw new \InvalidArgumentException("Unknown dimension: {$dimension}");

        return new $class($provider, new ScoreCalculator());
    }

    /**
     * بناء Orchestrator كامل من خريطة قوائم الاستجابات لكل بُعد.
     *
     * @param  array<string, list<array<string, mixed>>|'fail'>  $queues
     */
    public static function orchestrator(array $queues): EvaluationOrchestrator
    {
        $providers = [];
        foreach (array_keys(self::DIMENSIONS) as $dimension) {
            $providers[$dimension] = self::providerFor($queues, $dimension);
        }

        return self::orchestratorWithProviders($providers);
    }

    /**
     * بناء Orchestrator كامل من مزوّدات وهمية جاهزة (لمراقبة استدعاءات كل بُعد).
     *
     * @param  array<string, FakeAiProvider>  $providers
     */
    public static function orchestratorWithProviders(array $providers): EvaluationOrchestrator
    {
        $router = self::routerFromProviders($providers);

        return new EvaluationOrchestrator(
            router: $router,
            calculator: new ScoreCalculator(),
            validator: new EvaluationOutputValidator(),
            consensusAgent: new ConsensusAgent($router),
        );
    }

    /**
     * بناء McpRouter مسجّلاً بوكيل لكل بُعد.
     *
     * @param  array<string, list<array<string, mixed>>|'fail'>  $queues
     */
    public static function router(array $queues): McpRouter
    {
        $providers = [];
        foreach (array_keys(self::DIMENSIONS) as $dimension) {
            $providers[$dimension] = self::providerFor($queues, $dimension);
        }

        return self::routerFromProviders($providers);
    }

    /**
     * بناء McpRouter من مزوّدات وهمية جاهزة.
     *
     * @param  array<string, FakeAiProvider>  $providers
     */
    public static function routerFromProviders(array $providers): McpRouter
    {
        $calculator = new ScoreCalculator();
        $agents = [];

        foreach (self::DIMENSIONS as $dimension => $class) {
            $provider = $providers[$dimension] ?? new FakeAiProvider([], failReason: 'network');
            $agents[] = new $class($provider, $calculator);
        }

        return new McpRouter($agents);
    }

    /**
     * @param  array<string, list<array<string, mixed>>|'fail'>  $queues
     */
    public static function providerFor(array $queues, string $dimension): FakeAiProvider
    {
        $queue = $queues[$dimension] ?? [];

        if ($queue === 'fail') {
            return new FakeAiProvider([], failReason: 'network');
        }

        return new FakeAiProvider(is_array($queue) ? array_values($queue) : []);
    }

    /**
     * معايير فرعية بقيمة أساس موحّدة لكل معايير البُعد.
     *
     * @return array<string, float>
     */
    public static function subScores(string $dimension, float $base = 75.0): array
    {
        $subScores = [];
        foreach (array_keys((array) config('ai.sub_weights.' . $dimension, [])) as $criterion) {
            $subScores[$criterion] = $base;
        }

        return $subScores;
    }

    /**
     * باني Prompt للبُعد (لفحص محتوى الـ Prompt في الاختبارات).
     */
    public static function prompt(string $dimension): PromptsContract
    {
        $class = self::PROMPTS[$dimension] ?? throw new \InvalidArgumentException("Unknown dimension: {$dimension}");

        return new $class();
    }
}
