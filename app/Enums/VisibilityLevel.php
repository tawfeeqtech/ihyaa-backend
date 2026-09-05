<?php

namespace App\Enums;

/**
 * مستوى الإفصاح عن تقرير AI — docs/api/enums.md §1.4 · design-decisions §5.2.
 *
 * 1 = زائر غير مسجّل: التقييم الكلي (overall_score) فقط
 * 2 = أي مستخدم مسجّل الدخول (غير مالك): الكلي + درجات الأبعاد الخمسة + بيانات الرسم الراداري
 * 3 = صاحب المشروع دائماً + المستثمر بعد قبول طلب الاهتمام: كل شيء (فجوات، توصيات، مهارات، PDF)
 */
enum VisibilityLevel: int
{
    case VISITOR = 1;
    case REGISTERED = 2;
    case AFTER_AGREEMENT = 3;

    public function label(): string
    {
        return match ($this) {
            self::VISITOR => 'زائر',
            self::REGISTERED => 'مسجل',
            self::AFTER_AGREEMENT => 'بعد الاتفاق',
        };
    }
}
