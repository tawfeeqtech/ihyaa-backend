<?php

namespace App\Ai\Prompts;

/**
 * Prompt بُعد الجودة التقنية (Technical Quality — plan.md §1.1 / §3.3).
 * المعايير: code_structure · architecture · testing · ci_cd · documentation.
 */
final class TechnicalQualityPrompt extends AbstractPrompt
{
    public function dimension(): string
    {
        return 'technical_quality';
    }

    protected function dimensionLabel(): string
    {
        return 'الجودة التقنية للحل';
    }

    protected function criteria(): array
    {
        return [
            'code_structure' => 'تنظيم الكود ووضوحه وقابلية قراءته وفصل المسؤوليات',
            'architecture' => 'جودة التصميم المعماري وقابلية التوسع وصيانة النظام',
            'testing' => 'وجود الاختبارات الآلية وشموليتها وتغطيتها',
            'ci_cd' => 'وجود التكامل والتسليم المستمر (CI/CD) وخطوات النشر',
            'documentation' => 'توثيق الكود وملفات README وتعليمات التشغيل',
        ];
    }
}
