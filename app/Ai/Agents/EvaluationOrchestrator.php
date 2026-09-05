<?php

namespace App\Ai\Agents;

use App\Ai\Agents\SubAgents\AbstractSubAgent;
use App\Ai\Dtos\DimensionResult;
use App\Ai\Dtos\EvaluationReport;
use App\Ai\Mcp\McpRequest;
use App\Ai\Mcp\McpRouter;
use App\Ai\Validation\EvaluationOutputValidator;
use App\Support\ScoreCalculator;
use RuntimeException;

/**
 * منسّق التقييم (plan.md §1.1 — SRS-AI-O01/O02/O03).
 *
 * التدفق:
 *   1. يبني سياقاً مخصصاً لكل بُعد (SRS-AI-O02 — Technical يحصل على GitHub/README،
 *      Market على معلومات العمل، Team على بيانات الفريق...).
 *   2. يوزّع 5 طلبات MCP عبر McpRouter (agent.{dimension}.evaluate).
 *   3. يجمع DimensionResults ويحسب overall_score عبر ScoreCalculator (plan.md §1.2).
 *   4. عند انحراف بُعد > العتبة (config('ai.consensus_threshold')) يشغّل ConsensusAgent — جولة واحدة كحد أقصى.
 *   5. يولّد gap_analysis + recommendations + required_skills + warnings ويجمّع EvaluationReport.
 *   6. يتحقق من صحة المخرجات (EvaluationOutputValidator — SRS-TEST-AI-10).
 *
 * الجزئية (SRS-AI-F03): 3-4 أبعاد → تقرير جزئي ناجح؛ أقل من 3 → RuntimeException (تُترجم لاحقاً إلى failed).
 */
class EvaluationOrchestrator
{
    /** ترتيب الأبعاد الثابت (plan.md §1.4 — معرّف الطلب = evaluation_id × 10 + index). */
    public const DIMENSIONS = [
        'technical_quality' => 0,
        'innovation' => 1,
        'market_viability' => 2,
        'team_completeness' => 3,
        'documentation' => 4,
    ];

    /** معامل تخفيض الثقة للتقييم الجزئي (plan.md §1.6). */
    private const PARTIAL_CONFIDENCE_FACTOR = 0.8;

    /** خريطة البُعد ← مفتاح gap_analysis. */
    private const DIMENSION_GAP_KEY = [
        'technical_quality' => 'technical_gaps',
        'market_viability' => 'market_gaps',
        'team_completeness' => 'team_gaps',
        'documentation' => 'documentation_gaps',
        'innovation' => 'market_gaps', // فراغ الابتكار يؤثر على التموقع السوقي
    ];

    /** خريطة المعيار الفرعي ← وصف عربي (لتحليل الفجوات). */
    private const CRITERIA_LABELS = [
        'technical_quality' => [
            'code_structure' => 'تنظيم الكود ووضوحه',
            'architecture' => 'التصميم المعماري',
            'testing' => 'الاختبارات الآلية',
            'ci_cd' => 'التكامل والتسليم المستمر',
            'documentation' => 'توثيق الكود',
        ],
        'innovation' => [
            'novelty' => 'حداثة الفكرة',
            'problem_originality' => 'أصالة المشكلة',
            'approach_creativity' => 'إبداع المنهجية',
        ],
        'market_viability' => [
            'problem_clarity' => 'وضوح المشكلة',
            'market_size_estimation' => 'تقدير حجم السوق',
            'business_model_potential' => 'نموذج العمل',
            'competitive_awareness' => 'الوعي التنافسي',
        ],
        'team_completeness' => [
            'skill_diversity' => 'تنوع المهارات',
            'relevant_experience' => 'الخبرة ذات الصلة',
            'role_clarity' => 'وضوح الأدوار',
        ],
        'documentation' => [
            'project_description' => 'وصف المشروع',
            'objectives_clarity' => 'وضوح الأهداف',
            'supporting_docs_quality' => 'جودة المستندات الداعمة',
            'roadmap_clarity' => 'خارطة الطريق',
        ],
    ];

    public function __construct(
        private readonly McpRouter $router,
        private readonly ScoreCalculator $calculator,
        private readonly EvaluationOutputValidator $validator,
        private readonly ConsensusAgent $consensusAgent,
    ) {
    }

    /**
     * تنفيذ تقييم كامل.
     *
     * @param  array<string, mixed>  $input  project_id · evaluation_id · description · github_readme ·
     *                                       docs_meta · team · business_info · technologies · tags · category
     *                                       · roadmap · video_description · model_used (اختياري)
     * @param  string  $language  'ar' | 'en'
     *
     * @throws RuntimeException إذا اكتمل أقل من `min_dimensions_for_partial` أبعاد
     */
    public function evaluate(array $input, string $language = 'ar'): EvaluationReport
    {
        $evaluationId = isset($input['evaluation_id']) ? (int) $input['evaluation_id'] : null;
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : null;

        $contexts = $this->buildDimensionContexts($input);

        [$results, $failures] = $this->dispatchDimensions($contexts, $evaluationId, $projectId, $language);

        // الإجماع يعمل فقط عند اكتمال الأبعاد الخمسة (SRS-AI-O03).
        if (count($results) === count(self::DIMENSIONS)) {
            $results = $this->applyConsensus($results, $contexts, $language, $evaluationId, $projectId);
        }

        $completedCount = count($results);
        $minPartial = (int) config('ai.min_dimensions_for_partial', 3);

        if ($completedCount >= count(self::DIMENSIONS)) {
            $overall = $this->calculator->calculate($this->scoresOf($results));
            $partialDimensions = [];
        } elseif ($completedCount >= $minPartial) {
            $overall = $this->partialScore($results);
            $partialDimensions = $this->missingDimensions($results);
        } else {
            throw new RuntimeException(sprintf(
                'AI evaluation failed: only %d/%d dimensions succeeded (min %d for partial).',
                $completedCount,
                count(self::DIMENSIONS),
                $minPartial
            ));
        }

        $report = new EvaluationReport(
            overallScore: $overall,
            dimensions: $results,
            gapAnalysis: $this->gapAnalysis($results),
            recommendations: $this->recommendations($results),
            requiredSkills: $this->requiredSkills($results),
            confidenceScore: $this->confidenceScore($results, $partialDimensions),
            warnings: $this->collectWarnings($results, $failures, $partialDimensions),
            partialDimensions: $partialDimensions,
            modelUsed: isset($input['model_used']) ? (string) $input['model_used'] : null,
            evaluationTimestamp: now()->toIso8601String(),
        );

        $this->validator->assertValid($report->toArray());

        return $report;
    }

    /**
     * بناء سياق مخصص لكل بُعد (SRS-AI-O02) — لا يُمرَّر سياق غير ذي صلة.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, mixed>>
     */
    public function buildDimensionContexts(array $input): array
    {
        $description = (string) ($input['description'] ?? '');

        return [
            'technical_quality' => [
                'description' => $description,
                'github_readme' => $input['github_readme'] ?? null,
                'docs_meta' => $input['docs_meta'] ?? [],
                'technologies' => $input['technologies'] ?? [],
                'video_description' => $input['video_description'] ?? null,
            ],
            'innovation' => [
                'description' => $description,
                'category' => $input['category'] ?? null,
                'tags' => $input['tags'] ?? [],
                'video_description' => $input['video_description'] ?? null,
            ],
            'market_viability' => [
                'description' => $description,
                'business_info' => $input['business_info'] ?? null,
                'market' => $input['market'] ?? null,
                'competitors' => $input['competitors'] ?? [],
                'category' => $input['category'] ?? null,
            ],
            'team_completeness' => [
                'description' => $description,
                'team' => $input['team'] ?? [],
            ],
            'documentation' => [
                'description' => $description,
                'docs_meta' => $input['docs_meta'] ?? [],
                'roadmap' => $input['roadmap'] ?? null,
                'video_description' => $input['video_description'] ?? null,
            ],
        ];
    }

    /**
     * توزيع الطلبات على McpRouter وجمع النتائج/الإخفاقات.
     *
     * @param  array<string, array<string, mixed>>  $contexts
     * @return array{0: array<string, DimensionResult>, 1: array<string, \App\Ai\Mcp\McpError>}
     */
    private function dispatchDimensions(array $contexts, ?int $evaluationId, ?int $projectId, string $language): array
    {
        $results = [];
        $failures = [];

        foreach ($contexts as $dimension => $context) {
            $response = $this->router->dispatch(new McpRequest(
                method: "agent.{$dimension}.evaluate",
                params: [
                    'evaluation_id' => $evaluationId,
                    'project_id' => $projectId,
                    'language' => $language,
                    'context' => $context,
                    'schema_version' => '1.0',
                ],
                id: $this->requestId($evaluationId, $dimension),
            ));

            if ($response->isSuccess() && is_array($response->result)) {
                $results[$dimension] = AbstractSubAgent::dimensionResultFromArray($dimension, $response->result);
            } else {
                $failures[$dimension] = $response->error;
            }
        }

        return [$results, $failures];
    }

    /**
     * تشغيل جولة إجماع واحدة على أكثر بُعد انحرافاً إن تجاوز العتبة.
     *
     * @param  array<string, DimensionResult>  $results
     * @param  array<string, array<string, mixed>>  $contexts
     * @return array<string, DimensionResult>
     */
    private function applyConsensus(array $results, array $contexts, string $language, ?int $evaluationId, ?int $projectId): array
    {
        $deviant = $this->findDeviantDimension($results);

        if ($deviant === null) {
            return $results;
        }

        $others = $results;
        $deviantResult = $others[$deviant];
        unset($others[$deviant]);

        $revised = $this->consensusAgent->run(
            deviant: $deviantResult,
            otherDimensions: $others,
            baseContext: $contexts[$deviant] ?? [],
            language: $language,
            evaluationId: $evaluationId,
            projectId: $projectId,
        );

        if ($revised !== null) {
            $results[$deviant] = $revised;
        }

        return $results;
    }

    /**
     * تحديد أكثر بُعد انحرافاً عن متوسط البقية بما يتجاوز العتبة.
     *
     * @param  array<string, DimensionResult>  $results
     */
    private function findDeviantDimension(array $results): ?string
    {
        $threshold = (float) config('ai.consensus_threshold', 20);
        $deviant = null;
        $maxDeviation = 0.0;

        foreach ($results as $dimension => $result) {
            $others = $results;
            unset($others[$dimension]);

            if ($others === []) {
                continue;
            }

            $meanOthers = array_sum(array_map(fn (DimensionResult $r) => $r->score, $others)) / count($others);
            $deviation = abs($result->score - $meanOthers);

            if ($deviation > $threshold && $deviation > $maxDeviation) {
                $maxDeviation = $deviation;
                $deviant = $dimension;
            }
        }

        return $deviant;
    }

    /**
     * @param  array<string, DimensionResult>  $results
     */
    private function scoresOf(array $results): array
    {
        $scores = [];
        foreach ($results as $dimension => $result) {
            $scores[$dimension] = $result->score;
        }

        return $scores;
    }

    /**
     * المتوسط الموزون المعاد تطبيعه للأبعاد المكتملة فقط (SRS-AI-F03).
     *
     * @param  array<string, DimensionResult>  $results
     */
    private function partialScore(array $results): float
    {
        $weights = (array) config('ai.weights', []);
        $weightSum = 0.0;
        $total = 0.0;

        foreach ($results as $dimension => $result) {
            $weight = isset($weights[$dimension]) ? (float) $weights[$dimension] : 0.0;
            $weightSum += $weight;
            $total += $result->score * $weight;
        }

        if ($weightSum <= 0.0) {
            throw new RuntimeException('Cannot compute partial score: no weights available for completed dimensions.');
        }

        return round($total / $weightSum, 1);
    }

    /**
     * @param  array<string, DimensionResult>  $results
     * @return list<string>
     */
    private function missingDimensions(array $results): array
    {
        return array_values(array_diff(array_keys(self::DIMENSIONS), array_keys($results)));
    }

    /**
     * بناء تحليل الفجوات من نقاط الضعف والمعايير المنخفضة (data-model.md §2.2).
     *
     * @param  array<string, DimensionResult>  $results
     * @return array<string, list<string>>
     */
    private function gapAnalysis(array $results): array
    {
        $gaps = array_fill_keys(['technical_gaps', 'market_gaps', 'team_gaps', 'documentation_gaps'], []);

        foreach ($results as $dimension => $result) {
            $bucket = self::DIMENSION_GAP_KEY[$dimension] ?? null;

            if ($bucket === null || ! isset($gaps[$bucket])) {
                continue;
            }

            foreach ($result->weaknesses as $weakness) {
                $gaps[$bucket][] = $weakness;
            }

            $labels = self::CRITERIA_LABELS[$dimension] ?? [];
            foreach ($result->subScores as $criterion => $score) {
                if ($score < 60.0) {
                    $label = $labels[$criterion] ?? $criterion;
                    $gaps[$bucket][] = sprintf('ضعف في «%s» (الدرجة: %s)', $label, number_format($score, 1));
                }
            }
        }

        return array_map(fn (array $list) => array_values(array_unique(array_slice($list, 0, 6))), $gaps);
    }

    /**
     * توليد توصيات من المعايير المنخفضة عبر ثلاثة آفاق (data-model.md §2.2).
     *
     * @param  array<string, DimensionResult>  $results
     * @return array<string, list<string>>
     */
    private function recommendations(array $results): array
    {
        $recommendations = ['immediate' => [], 'short_term' => [], 'long_term' => []];

        foreach ($results as $dimension => $result) {
            $labels = self::CRITERIA_LABELS[$dimension] ?? [];

            foreach ($result->subScores as $criterion => $score) {
                $label = $labels[$criterion] ?? $criterion;

                if ($score < 40.0) {
                    $recommendations['immediate'][] = sprintf('عالج فوراً: %s', $label);
                } elseif ($score < 65.0) {
                    $recommendations['short_term'][] = sprintf('حسّن على المدى القصير: %s', $label);
                } elseif ($score < 80.0 && in_array($criterion, ['ci_cd', 'roadmap_clarity', 'supporting_docs_quality'], true)) {
                    $recommendations['long_term'][] = sprintf('خطة طويلة الأمد: %s', $label);
                }
            }
        }

        return array_map(fn (array $list) => array_values(array_unique(array_slice($list, 0, 5))), $recommendations);
    }

    /**
     * اشتقاق المهارات المطلوبة من الفجوات الحرجة.
     *
     * @param  array<string, DimensionResult>  $results
     * @return list<string>
     */
    private function requiredSkills(array $results): array
    {
        $skills = [];

        $team = $results['team_completeness'] ?? null;
        if ($team !== null) {
            if (($team->subScores['skill_diversity'] ?? 100.0) < 60.0) {
                $skills[] = 'تخصص تقني إضافي (حسب مجال المشروع)';
            }
            if (($team->subScores['relevant_experience'] ?? 100.0) < 60.0) {
                $skills[] = 'مستشار/خبير في مجال المشروع';
            }
            if (($team->subScores['role_clarity'] ?? 100.0) < 60.0) {
                $skills[] = 'منسق مشروع / مدير مشروع';
            }
        }

        $technical = $results['technical_quality'] ?? null;
        if ($technical !== null) {
            if (($technical->subScores['testing'] ?? 100.0) < 50.0) {
                $skills[] = 'مهندس اختبارات (QA)';
            }
            if (($technical->subScores['ci_cd'] ?? 100.0) < 50.0) {
                $skills[] = 'مهندس DevOps / CI-CD';
            }
        }

        $market = $results['market_viability'] ?? null;
        if ($market !== null && ($market->subScores['business_model_potential'] ?? 100.0) < 50.0) {
            $skills[] = 'خبير نموذج عمل وتسويق';
        }

        $documentation = $results['documentation'] ?? null;
        if ($documentation !== null && ($documentation->subScores['roadmap_clarity'] ?? 100.0) < 50.0) {
            $skills[] = 'مخطط منتج (Product Manager)';
        }

        return array_values(array_unique(array_slice($skills, 0, 6)));
    }

    /**
     * تجميع التحذيرات: تحذيرات الأبعاد + إخفاقات الأبعاد الناقصة (SRS-AI-F03).
     *
     * @param  array<string, DimensionResult>  $results
     * @param  array<string, \App\Ai\Mcp\McpError>  $failures
     * @param  list<string>  $partialDimensions
     * @return list<string>
     */
    private function collectWarnings(array $results, array $failures, array $partialDimensions): array
    {
        $warnings = [];

        foreach ($results as $dimension => $result) {
            foreach ($result->warnings as $warning) {
                $warnings[] = $warning;
            }
        }

        foreach ($failures as $dimension => $error) {
            $reason = $error?->message ?? 'unknown_error';
            $warnings[] = "لم يكتمل بُعد «{$dimension}» — السبب: {$reason}";
        }

        foreach ($partialDimensions as $dimension) {
            if (! isset($failures[$dimension])) {
                $warnings[] = "بُعد «{$dimension}» غير متوفر في هذا التقييم.";
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * حساب درجة الثقة الإجمالية (متوسط ثقة الأبعاد، مخفَّضة للجزئي).
     *
     * @param  array<string, DimensionResult>  $results
     * @param  list<string>  $partialDimensions
     */
    private function confidenceScore(array $results, array $partialDimensions): float
    {
        $confidences = [];
        foreach ($results as $result) {
            if ($result->confidence !== null) {
                $confidences[] = $result->confidence;
            }
        }

        $base = $confidences === []
            ? 80.0
            : (array_sum($confidences) / count($confidences)) * 100.0;

        $confidence = min(100.0, max(0.0, $base));

        if ($partialDimensions !== []) {
            $confidence *= self::PARTIAL_CONFIDENCE_FACTOR;
        }

        return round($confidence, 1);
    }

    private function requestId(?int $evaluationId, string $dimension): ?int
    {
        if ($evaluationId === null) {
            return null;
        }

        return $evaluationId * 10 + (self::DIMENSIONS[$dimension] ?? 0);
    }
}
