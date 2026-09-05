<?php

namespace App\Enums;

/**
 * حالة المشروع التجارية (Lifecycle State) — docs/api/enums.md §1.3 · SRS-F02-01.
 * حقل `status` في جدول projects — يُعرض على بطاقة المشروع.
 * تعديله من الحقول الجوهرية التي تقترح إعادة التقييم (SRS-F04-02).
 */
enum ProjectState: string
{
    case COMPLETED = 'completed';
    case NEEDS_DEVELOPMENT = 'needs_development';
    case NEEDS_FUNDING = 'needs_funding';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => 'مكتمل',
            self::NEEDS_DEVELOPMENT => 'يحتاج تطوير',
            self::NEEDS_FUNDING => 'يحتاج تمويل',
        };
    }
}
