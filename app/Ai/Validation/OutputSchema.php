<?php

namespace App\Ai\Validation;

/**
 * تعريف مخطط مخرجات التقييم (data-model.md §2.2 — SRS-5.4.6.3 / SRS-TEST-AI-10).
 *
 * يُستخدم من EvaluationOutputValidator للتحقق من بنية JSON المخزَّن في evaluations.result:
 * schema_version · overall_score · dimensions · gap_analysis · recommendations
 * · required_skills · warnings (+ اختيارية: confidence_score · partial_dimensions · model_used · evaluation_timestamp).
 */
final class OutputSchema
{
    public const SCHEMA_VERSION = '1.0';

    public const MIN_SCORE = 0.0;
    public const MAX_SCORE = 100.0;

    /** المفاتيح العلوية الإلزامية في تقرير صالح. */
    public const TOP_LEVEL_REQUIRED = [
        'schema_version',
        'overall_score',
        'dimensions',
        'gap_analysis',
        'recommendations',
        'required_skills',
        'warnings',
    ];

    /** مفاتيح بُعد مكتمل إلزامية. */
    public const DIMENSION_REQUIRED = [
        'score',
        'sub_scores',
        'strengths',
        'weaknesses',
    ];

    /** مفاتيح gap_analysis الإلزامية. */
    public const GAP_ANALYSIS_KEYS = [
        'technical_gaps',
        'market_gaps',
        'team_gaps',
        'documentation_gaps',
    ];

    /** مفاتيح recommendations الإلزامية. */
    public const RECOMMENDATION_KEYS = [
        'immediate',
        'short_term',
        'long_term',
    ];

    /**
     * قائمة الأبعاد المعروفة (من config/ai.php — مصدر الحقيقة).
     *
     * @return list<string>
     */
    public static function dimensions(): array
    {
        return array_keys((array) config('ai.weights', []));
    }

    /**
     * المعايير الفرعية لبُعد معيّن.
     *
     * @return list<string>
     */
    public static function subCriteria(string $dimension): array
    {
        return array_keys((array) config('ai.sub_weights.' . $dimension, []));
    }
}
