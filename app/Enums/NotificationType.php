<?php

namespace App\Enums;

/**
 * أنواع الإشعارات المعتمدة — SRS-F09 · docs/api/enums.md §2.9 · T018.
 *
 * الحرجان فقط (SC-002 · US-048): interest_new · evaluation_completed — يُبثان
 * فورياً عبر Reverb. البقية تُخزَّن وتُجلب عند إعادة التحميل (US-049).
 *
 * ملاحظة: قد توجد أنواع إضافية تاريخية خارج الكتالوج (welcome, evaluation_failed,
 * evaluation_partial, project_updated) — تُعامَل دائماً كغير حرجة لأنها ليست في
 * config/notifications.php critical_types.
 */
enum NotificationType: string
{
    case INTEREST_NEW = 'interest_new';
    case EVALUATION_COMPLETED = 'evaluation_completed';
    case INTEREST_ACCEPTED = 'interest_accepted';
    case INTEREST_REJECTED = 'interest_rejected';
    case INTEREST_CANCELLED = 'interest_cancelled';
    case ANALYSIS_COMPLETED = 'analysis_completed';
    case PDF_GENERATION_FAILED = 'pdf_generation_failed';

    /** قراءة الحرجية من config/notifications.php (مصدر الحقيقة الوحيد). */
    public function isCritical(): bool
    {
        return in_array($this->value, config('notifications.critical_types', []), true);
    }

    /** كل الأنواع السبعة المعتمدة في الكتالوج. */
    public static function catalog(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
