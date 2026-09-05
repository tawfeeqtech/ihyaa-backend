<?php

namespace App\Ai\Prompts;

/**
 * Prompt بُعد الجدوى السوقية (Market Viability — plan.md §1.1 / §3.3).
 * المعايير: problem_clarity · market_size_estimation · business_model_potential · competitive_awareness.
 */
final class MarketViabilityPrompt extends AbstractPrompt
{
    public function dimension(): string
    {
        return 'market_viability';
    }

    protected function dimensionLabel(): string
    {
        return 'الجدوى السوقية';
    }

    protected function criteria(): array
    {
        return [
            'problem_clarity' => 'وضوح المشكلة وفهم العميل المستهدف واحتياجاته',
            'market_size_estimation' => 'تقدير حجم السوق والفرصة السوقية المتاحة',
            'business_model_potential' => 'إمكانية نموذج العمل وتحقيق الإيراد والاستدامة المالية',
            'competitive_awareness' => 'الوعي بالمنافسين ووضوح عناصر التمييز التنافسية',
        ];
    }
}
