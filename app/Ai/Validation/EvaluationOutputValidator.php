<?php

namespace App\Ai\Validation;

use InvalidArgumentException;

/**
 * مُتحقق صحة مخرجات التقييم (plan.md §1.1 — SRS-TEST-AI-10: اجتياز 100%).
 *
 * يتحقق من:
 *   - المفاتيح العلوية الإلزامية ووجود schema_version المدعوم.
 *   - نطاق overall_score ودرجات الأبعاد والمعايير الفرعية (0-100).
 *   - بنية dimensions (كل بُعد: score · sub_scores · strengths · weaknesses).
 *   - تطابق المعايير الفرعية مع `config('ai.sub_weights')` (لا نقص ولا زيادة).
 *   - بنية gap_analysis · recommendations · required_skills · warnings.
 *   - نطاق confidence_score (0-100 على مستوى التقرير، 0-1 على مستوى البُعد).
 *
 * مخرج `validate()` مصفوفة { valid, errors } — بلا رمي استثناءات.
 * `assertValid()` يرمي InvalidArgumentException عند عدم الصلاحية (يستخدمه Orchestrator).
 */
class EvaluationOutputValidator
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $report): array
    {
        $errors = [];

        foreach (OutputSchema::TOP_LEVEL_REQUIRED as $key) {
            if (! array_key_exists($key, $report)) {
                $errors[] = "missing required top-level key: {$key}";
            }
        }

        if (isset($report['schema_version']) && $report['schema_version'] !== OutputSchema::SCHEMA_VERSION) {
            $errors[] = 'unsupported schema_version: ' . (string) $report['schema_version'];
        }

        if (array_key_exists('overall_score', $report)) {
            $this->assertScoreRange($report['overall_score'], 'overall_score', $errors);
        }

        if (isset($report['confidence_score'])) {
            $this->assertScoreRange($report['confidence_score'], 'confidence_score', $errors);
        }

        if (array_key_exists('dimensions', $report)) {
            $this->assertDimensions($report['dimensions'], $errors);
        }

        if (array_key_exists('gap_analysis', $report)) {
            $this->assertStringListMap($report['gap_analysis'], OutputSchema::GAP_ANALYSIS_KEYS, 'gap_analysis', $errors);
        }

        if (array_key_exists('recommendations', $report)) {
            $this->assertStringListMap($report['recommendations'], OutputSchema::RECOMMENDATION_KEYS, 'recommendations', $errors);
        }

        if (array_key_exists('required_skills', $report)) {
            $this->assertStringList($report['required_skills'], 'required_skills', $errors);
        }

        if (array_key_exists('warnings', $report)) {
            $this->assertStringList($report['warnings'], 'warnings', $errors);
        }

        if (isset($report['partial_dimensions'])) {
            $this->assertDimensionKeys((array) $report['partial_dimensions'], 'partial_dimensions', $errors);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(array $report): void
    {
        $result = $this->validate($report);

        if (! $result['valid']) {
            throw new InvalidArgumentException(
                'Evaluation output validation failed: ' . implode('; ', $result['errors'])
            );
        }
    }

    /**
     * @param  mixed  $dimensions
     * @param  list<string>  $errors
     */
    private function assertDimensions(mixed $dimensions, array &$errors): void
    {
        if (! is_array($dimensions) || $dimensions === []) {
            $errors[] = 'dimensions must be a non-empty object';

            return;
        }

        $known = OutputSchema::dimensions();

        foreach ($dimensions as $dimension => $data) {
            if (! in_array($dimension, $known, true)) {
                $errors[] = "unknown dimension: {$dimension}";

                continue;
            }

            if (! is_array($data)) {
                $errors[] = "dimension [{$dimension}] must be an object";

                continue;
            }

            foreach (OutputSchema::DIMENSION_REQUIRED as $key) {
                if (! array_key_exists($key, $data)) {
                    $errors[] = "dimension [{$dimension}] missing required key: {$key}";
                }
            }

            if (isset($data['score'])) {
                $this->assertScoreRange($data['score'], "dimensions.{$dimension}.score", $errors);
            }

            if (isset($data['confidence'])) {
                $this->assertConfidenceRange($data['confidence'], "dimensions.{$dimension}.confidence", $errors);
            }

            if (isset($data['sub_scores'])) {
                $this->assertSubScores($dimension, $data['sub_scores'], $errors);
            }

            if (isset($data['strengths'])) {
                $this->assertStringList($data['strengths'], "dimensions.{$dimension}.strengths", $errors);
            }

            if (isset($data['weaknesses'])) {
                $this->assertStringList($data['weaknesses'], "dimensions.{$dimension}.weaknesses", $errors);
            }
        }
    }

    /**
     * @param  mixed  $subScores
     * @param  list<string>  $errors
     */
    private function assertSubScores(string $dimension, mixed $subScores, array &$errors): void
    {
        if (! is_array($subScores) || $subScores === []) {
            $errors[] = "dimensions.{$dimension}.sub_scores must be an object";

            return;
        }

        $expected = OutputSchema::subCriteria($dimension);

        foreach ($expected as $criterion) {
            if (! array_key_exists($criterion, $subScores)) {
                $errors[] = "dimensions.{$dimension}.sub_scores missing criterion: {$criterion}";

                continue;
            }
            $this->assertScoreRange($subScores[$criterion], "dimensions.{$dimension}.sub_scores.{$criterion}", $errors);
        }

        foreach (array_keys($subScores) as $criterion) {
            if (! in_array($criterion, $expected, true)) {
                $errors[] = "dimensions.{$dimension}.sub_scores has unknown criterion: {$criterion}";
            }
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $errors
     */
    private function assertScoreRange(mixed $value, string $path, array &$errors): void
    {
        if (! is_numeric($value)) {
            $errors[] = "{$path} must be numeric";

            return;
        }

        $score = (float) $value;
        if ($score < OutputSchema::MIN_SCORE || $score > OutputSchema::MAX_SCORE) {
            $errors[] = "{$path} out of range (0-100): {$score}";
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $errors
     */
    private function assertConfidenceRange(mixed $value, string $path, array &$errors): void
    {
        if (! is_numeric($value)) {
            $errors[] = "{$path} must be numeric";

            return;
        }

        $confidence = (float) $value;
        if ($confidence < 0.0 || $confidence > 1.0) {
            $errors[] = "{$path} out of range (0-1): {$confidence}";
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $errors
     */
    private function assertStringList(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[] = "{$path} must be an array";

            return;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                $errors[] = "{$path} must contain only strings";

                return;
            }
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $requiredKeys
     * @param  list<string>  $errors
     */
    private function assertStringListMap(mixed $value, array $requiredKeys, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[] = "{$path} must be an object";

            return;
        }

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path} missing key: {$key}";

                continue;
            }
            $this->assertStringList($value[$key], "{$path}.{$key}", $errors);
        }
    }

    /**
     * @param  mixed  $value
     * @param  list<string>  $errors
     */
    private function assertDimensionKeys(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            $errors[] = "{$path} must be an array";

            return;
        }

        $known = OutputSchema::dimensions();
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $known, true)) {
                $errors[] = "{$path} contains an unknown dimension: " . (is_string($item) ? $item : gettype($item));
            }
        }
    }
}
