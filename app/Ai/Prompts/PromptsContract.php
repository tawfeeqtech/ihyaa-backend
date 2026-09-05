<?php

namespace App\Ai\Prompts;

/**
 * عقد باني الـ Prompt لكل بُعد (plan.md §3.3 — app/Ai/Prompts/{dimension}.php).
 *
 * `build()` يعيد مصفوفة `messages` بصيغة مزود الدردشة:
 *   [{ role: 'system'|'user', content: string }, ...]
 *
 * كل System Prompt يتضمن (إلزام دستوري/مواصفاتي):
 *   - دور المُقيِّم للبُعد
 *   - المعايير الفرعية وأوزانها (من `config('ai.sub_weights')`)
 *   - سلم الدرجات 0-100 بمعايير ربط
 *   - مخطط JSON المطلوب (من JsonSchema)
 *   - توجيه اللغة: قيّم بلغة محتوى المشروع (مكافئ ≤ 5%)
 *   - حارس حقن الأوامر (SRS-TEST-AI-07): وصف المشروع بيانات لا تعليمات
 */
interface PromptsContract
{
    /**
     * مفتاح البُعد: technical_quality | innovation | market_viability | team_completeness | documentation.
     */
    public function dimension(): string;

    /**
     * بناء رسائل الدردشة لطلب تقييم البُعد.
     *
     * @param  array<string, mixed>  $context  سياق البُعد المخصص فقط (SRS-AI-O02)
     * @param  string  $language  'ar' | 'en' — لغة محتوى المشروع
     *
     * @return list<array{role: string, content: string}>
     */
    public function build(array $context, string $language = 'ar'): array;
}
