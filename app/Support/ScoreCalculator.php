<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * حساب المتوسط الموزون لدرجات التقييم (plan.md §1.2 — SRS-5.4.6 / FR-201).
 *
 * القاعدة:
 *   overall_score = round(Σ score_dimension × weight_dimension, 1)
 *
 * الأوزان تُقرأ من `config('ai.weights')` (أبعاد) و`config('ai.sub_weights')` (معايير فرعية)
 * وتُفرض نزاهتها: مجموع الأوزان = 1.0 لكل مستوى (data-model.md §2.3 — اختبار آلي US-015-S2).
 */
class ScoreCalculator
{
    /**
     * المتوسط الموزون للأبعاد الخمسة، مقرّباً لأقرب 0.1.
     *
     * @param  array<string, float>  $dimensionScores  درجة لكل بُعد (technical_quality, innovation, ...)
     * @return float 0.0 – 100.0
     *
     * @throws InvalidArgumentException إذا كان مجموع الأوزان ≠ 1.0 أو نَقصت درجة بُعد.
     */
    public function calculate(array $dimensionScores): float
    {
        $weights = $this->dimensionWeights();

        $total = 0.0;
        foreach ($weights as $dimension => $weight) {
            if (! array_key_exists($dimension, $dimensionScores)) {
                throw new InvalidArgumentException("Missing dimension score for: {$dimension}");
            }

            $total += $dimensionScores[$dimension] * $weight;
        }

        return $this->roundScore($total);
    }

    /**
     * المتوسط الموزون لمعايير بُعد واحد (sub-weights)، مقرّباً لأقرب 0.1.
     *
     * @param  string  $dimension  مفتاح البُعد (technical_quality | innovation | market_viability | team_completeness | documentation)
     * @param  array<string, float>  $subScores  درجة لكل معيار فرعي
     * @return float 0.0 – 100.0
     *
     * @throws InvalidArgumentException إذا كان البُعد غير معروف أو مجموع أوزانه ≠ 1.0 أو نَقصت درجة معيار.
     */
    public function calculateDimension(string $dimension, array $subScores): float
    {
        $subWeights = $this->subWeights($dimension);

        $total = 0.0;
        foreach ($subWeights as $criterion => $weight) {
            if (! array_key_exists($criterion, $subScores)) {
                throw new InvalidArgumentException("Missing sub-score for criterion: {$criterion} ({$dimension})");
            }

            $total += $subScores[$criterion] * $weight;
        }

        return $this->roundScore($total);
    }

    /**
     * فحص نزاهة الأوزان: يجب أن يكون المجموع 1.0 (بتسامح عائم).
     *
     * @param  array<string, float>  $weights
     * @param  string  $source  مفتاح الإعداد للتقرير الخطأ
     *
     * @throws InvalidArgumentException
     */
    public function assertWeightsIntegrity(array $weights, string $source = 'weights'): void
    {
        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 1e-9) {
            throw new InvalidArgumentException(
                sprintf('Weights integrity check failed for [%s]: sum = %.4f (expected 1.0)', $source, $sum)
            );
        }
    }

    /**
     * @return array<string, float>
     */
    protected function dimensionWeights(): array
    {
        $weights = (array) config('ai.weights');

        $this->assertWeightsIntegrity($weights, 'ai.weights');

        return $weights;
    }

    /**
     * @return array<string, float>
     */
    protected function subWeights(string $dimension): array
    {
        $subWeights = config("ai.sub_weights.{$dimension}");

        if (! is_array($subWeights) || $subWeights === []) {
            throw new InvalidArgumentException("Unknown dimension: {$dimension}");
        }

        $this->assertWeightsIntegrity($subWeights, "ai.sub_weights.{$dimension}");

        return $subWeights;
    }

    protected function roundScore(float $score): float
    {
        return round($score, 1);
    }
}
