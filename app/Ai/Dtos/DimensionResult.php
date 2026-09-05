<?php

namespace App\Ai\Dtos;

/**
 * نتيجة تقييم بُعد واحد (plan.md §1.4 — مخطط الاستجابة لكل Sub-Agent).
 * يُنتَج بواسطة Sub-Agent ويُستهلك بواسطة Orchestrator/ScoreCalculator.
 *
 * @immutable
 */
final class DimensionResult
{
    /**
     * @param  string  $dimension  مفتاح البُعد (technical_quality | innovation | market_viability | team_completeness | documentation)
     * @param  float  $score  0.0 – 100.0
     * @param  array<string, float>  $subScores  درجات المعايير الفرعية
     * @param  list<string>  $strengths
     * @param  list<string>  $weaknesses
     * @param  float|null  $confidence  0.0 – 1.0
     * @param  list<string>  $warnings  تحذيرات (بُعد مقيَّم جزئياً، سياق ناقص، ...)
     */
    public function __construct(
        public readonly string $dimension,
        public readonly float $score,
        public readonly array $subScores = [],
        public readonly array $strengths = [],
        public readonly array $weaknesses = [],
        public readonly ?float $confidence = null,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * تحويل إلى صيغة JSON المخزَّنة في evaluations.result.dimensions[dimension].
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'score' => $this->score,
            'sub_scores' => $this->subScores,
            'strengths' => $this->strengths,
            'weaknesses' => $this->weaknesses,
        ];

        if ($this->confidence !== null) {
            $payload['confidence'] = $this->confidence;
        }

        if ($this->warnings !== []) {
            $payload['warnings'] = $this->warnings;
        }

        return $payload;
    }
}
