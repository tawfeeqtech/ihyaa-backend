<?php

namespace App\Ai\Dtos;

/**
 * تقرير التقييم الكامل — مخطط §5.4.6.3 (data-model.md §2.2).
 * يُنتَج بواسطة Orchestrator ويُخزَّن في evaluations.result.
 *
 * @immutable
 */
final class EvaluationReport
{
    /**
     * @param  array<string, DimensionResult>  $dimensions  مفتاح: اسم البُعد
     * @param  array<string, list<string>>  $gapAnalysis  technical_gaps / market_gaps / team_gaps / documentation_gaps
     * @param  array<string, list<string>>  $recommendations  immediate / short_term / long_term
     * @param  list<string>  $requiredSkills
     * @param  list<string>  $warnings
     * @param  list<string>  $partialDimensions  أبعاد ناقصة عند status=partial
     */
    public function __construct(
        public readonly float $overallScore,
        public readonly array $dimensions = [],
        public readonly array $gapAnalysis = [],
        public readonly array $recommendations = [],
        public readonly array $requiredSkills = [],
        public readonly ?float $confidenceScore = null,
        public readonly array $warnings = [],
        public readonly string $schemaVersion = '1.0',
        public readonly array $partialDimensions = [],
        public readonly ?string $modelUsed = null,
        public readonly ?string $evaluationTimestamp = null,
    ) {
    }

    /**
     * @return array<string, mixed> صيغة result JSON كاملة (data-model.md §2.2)
     */
    public function toArray(): array
    {
        $dimensions = [];
        foreach ($this->dimensions as $name => $result) {
            $dimensions[$name] = $result instanceof DimensionResult ? $result->toArray() : $result;
        }

        $payload = [
            'schema_version' => $this->schemaVersion,
            'overall_score' => $this->overallScore,
            'dimensions' => $dimensions,
            'gap_analysis' => $this->gapAnalysis,
            'recommendations' => $this->recommendations,
            'required_skills' => $this->requiredSkills,
            'warnings' => $this->warnings,
        ];

        if ($this->confidenceScore !== null) {
            $payload['confidence_score'] = $this->confidenceScore;
        }

        if ($this->partialDimensions !== []) {
            $payload['partial_dimensions'] = $this->partialDimensions;
        }

        if ($this->modelUsed !== null) {
            $payload['model_used'] = $this->modelUsed;
        }

        if ($this->evaluationTimestamp !== null) {
            $payload['evaluation_timestamp'] = $this->evaluationTimestamp;
        }

        return $payload;
    }
}
