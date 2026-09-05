<?php

namespace App\Services\Evaluation;

use App\Ai\Agents\ConsensusAgent;
use App\Ai\Agents\EvaluationOrchestrator;
use App\Ai\Agents\SubAgents\DocumentationSubAgent;
use App\Ai\Agents\SubAgents\InnovationSubAgent;
use App\Ai\Agents\SubAgents\MarketViabilitySubAgent;
use App\Ai\Agents\SubAgents\SubAgentContract;
use App\Ai\Agents\SubAgents\TeamCompletenessSubAgent;
use App\Ai\Agents\SubAgents\TechnicalQualitySubAgent;
use App\Ai\Mcp\McpRouter;
use App\Ai\Providers\AiRequestLogger;
use App\Ai\Providers\ClaudeProvider;
use App\Ai\Providers\FallbackManager;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Validation\EvaluationOutputValidator;
use App\Support\ScoreCalculator;

/**
 * مصنع محرك التقييم (plan.md §1.1 — SRS-AI-O01/O02/O03).
 *
 * الاختبارات تثبّت وهمياً عبر `app()->instance(EvaluationOrchestrator::class, $fake)`
 * فيُعيد `make()` المثبَّت فوراً. في بيئة التشغيل يبني المحرك الحقيقي:
 * OpenAiProvider/ClaudeProvider ← FallbackManager (SRS-AI-F01/F02) ← FallbackProviderAdapter ← 5 Sub-Agents ← McpRouter.
 *
 * @final
 */
final class EvaluationEngineFactory
{
    /** أبعاد المحرك الخمسة بنفس ترتيب config('ai.weights') — plan.md §1.4. */
    private const DIMENSIONS = [
        TechnicalQualitySubAgent::class,
        InnovationSubAgent::class,
        MarketViabilitySubAgent::class,
        TeamCompletenessSubAgent::class,
        DocumentationSubAgent::class,
    ];

    /**
     * المحرك الفعّال: المثبَّت في الحاوية (اختبارات/Fakes) أو المحرك الحقيقي.
     */
    public function make(): EvaluationOrchestrator
    {
        if (app()->bound(EvaluationOrchestrator::class)) {
            return app(EvaluationOrchestrator::class);
        }

        return $this->build();
    }

    /**
     * بناء المحرك الحقيقي من config('ai').
     */
    public function build(): EvaluationOrchestrator
    {
        $openAi = new OpenAiProvider(config: (array) config('ai.openai', []));
        $claude = new ClaudeProvider(config: (array) config('ai.claude', []));

        $fallback = new FallbackManager(
            providers: ['openai' => $openAi, 'claude' => $claude],
            logger: new AiRequestLogger(),
            primaryProvider: (string) config('ai.primary_provider', 'openai'),
        );

        // محوّل واحد مشترك: كسّار الدائرة وعداد الإخفاقات مشتركان بين كل الأبعاد.
        $provider = new FallbackProviderAdapter($fallback);
        $calculator = new ScoreCalculator();

        $agents = array_map(
            fn (string $class): SubAgentContract => new $class($provider, $calculator),
            self::DIMENSIONS,
        );

        $router = new McpRouter($agents);

        return new EvaluationOrchestrator(
            router: $router,
            calculator: $calculator,
            validator: new EvaluationOutputValidator(),
            consensusAgent: new ConsensusAgent($router),
        );
    }
}
