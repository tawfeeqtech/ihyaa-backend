<?php

namespace App\Ai\Prompts;

use InvalidArgumentException;

/**
 * مخططات JSON للإخراج المنظّم لكل بُعد (plan.md §3.3 — SRS-AI-M03 / structured output).
 *
 * كل مخطط يعرّف عقداً صارماً (strict) لمخرجات Sub-Agent:
 *   score (0-100) · sub_scores (درجات المعايير الفرعية 0-100) · strengths · weaknesses
 *   confidence (0-1) · warnings (اختياريان)
 *
 * المعايير الفرعية لكل بُعد تُقرأ من `config('ai.sub_weights')` — مصدر الحقيقة الوحيد
 * (data-model.md §2.3) فلا يتكرر تعريفها في مكانين.
 */
final class JsonSchema
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * مخطط JSON Schema لمخرجات بُعد معيّن.
     *
     * @return array<string, mixed> صيغة `response_format.json_schema` لمزود OpenAI
     */
    public static function for(string $dimension): array
    {
        $criteria = self::subCriteria($dimension);

        $subScoreProps = [];
        foreach ($criteria as $criterion) {
            $subScoreProps[$criterion] = [
                'type' => 'number',
                'minimum' => 0,
                'maximum' => 100,
                'description' => 'درجة المعيار الفرعي (0-100)',
            ];
        }

        return [
            'name' => 'evaluation_' . $dimension,
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'score' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 100,
                        'description' => 'الدرجة الكلية للبُعد (0-100)',
                    ],
                    'sub_scores' => [
                        'type' => 'object',
                        'properties' => $subScoreProps,
                        'required' => $criteria,
                        'additionalProperties' => false,
                    ],
                    'strengths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'نقاط القوة الملاحظة',
                    ],
                    'weaknesses' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'نقاط الضعف الملاحظة',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'minimum' => 0,
                        'maximum' => 1,
                        'description' => 'ثقة المُقيِّم في النتيجة (0.0-1.0)',
                    ],
                    'warnings' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'تحذيرات حول نقص السياق أو عدم اليقين',
                    ],
                ],
                'required' => ['score', 'sub_scores', 'strengths', 'weaknesses'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * المخططات الكاملة لكل الأبعاد (للتوثيق/الاختبارات).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $schemas = [];
        foreach (array_keys((array) config('ai.sub_weights', [])) as $dimension) {
            $schemas[$dimension] = self::for($dimension);
        }

        return $schemas;
    }

    /**
     * قائمة المعايير الفرعية لبُعد (بترتيب config/ai.php).
     *
     * @return list<string>
     */
    public static function subCriteria(string $dimension): array
    {
        $subWeights = config("ai.sub_weights.{$dimension}");

        if (! is_array($subWeights) || $subWeights === []) {
            throw new InvalidArgumentException("Unknown dimension for JSON schema: {$dimension}");
        }

        return array_keys($subWeights);
    }
}
