<?php

namespace App\Enums;

/**
 * حالة طلب الاهتمام — docs/api/enums.md §1.5 · data-model.md §2.1.
 * pending → accepted (إنشاء PDF وكشف البريد) · pending → rejected (سبب اختياري)
 * pending → cancelled (المستثمر) · accepted → cancelled (المستثمر — يُحذف ملف PDF).
 * accepted_pending_document: حالة وسيطة عند فشل توليد PDF مؤقتاً (FR-310) —
 *   تُحال للمعالجة الخلفية عبر RetryPendingDocuments ثم تنتقل إلى accepted.
 * قبول/رفض طلب cancelled → خطأ 409 INTEREST_CANCELLED (UC-06 E3).
 */
enum InterestStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case ACCEPTED_PENDING_DOCUMENT = 'accepted_pending_document';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'معلق',
            self::ACCEPTED => 'مقبول',
            self::ACCEPTED_PENDING_DOCUMENT => 'مقبول بانتظار المستند',
            self::REJECTED => 'مرفوض',
            self::CANCELLED => 'ملغي',
        };
    }

    /** الحالات "النشطة" — يمنع تكرار طلب نشط + يسمح بالإلغاء (US-043/UC-07 E2). */
    public function isActive(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::ACCEPTED,
            self::ACCEPTED_PENDING_DOCUMENT,
        ], true);
    }
}
