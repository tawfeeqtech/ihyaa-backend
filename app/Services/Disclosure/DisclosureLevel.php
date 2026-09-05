<?php

namespace App\Services\Disclosure;

/**
 * مستويات مصفوفة الإفصاح عن تقرير AI — contracts/report-api.md §3 (US-029).
 *
 * | المستوى | من | محتوى التقرير المسموح |
 * |---|---|---|
 * | L1 | زائر (غير مصادق) | الدرجة الكلية فقط |
 * | L2 | مستخدم مسجل غير مالك، بلا اتفاق | + درجات الأبعاد الخمسة + الرادار |
 * | L3 | مستثمر بعد اتفاق نشط | كل شيء |
 * | EX | صاحب المشروع | كل شيء دائماً (استثناء مضمون — SRS-F05-05) |
 * | AD | المشرف | كل شيء |
 */
enum DisclosureLevel: string
{
    case L1 = 'L1';
    case L2 = 'L2';
    case L3 = 'L3';
    case EX = 'EX';
    case AD = 'AD';

    /** عنوان عربي مقروء للمستوى (يُستخدم في السجلات والردود). */
    public function label(): string
    {
        return match ($this) {
            self::L1 => 'زائر',
            self::L2 => 'مسجل',
            self::L3 => 'بعد اتفاق',
            self::EX => 'صاحب المشروع',
            self::AD => 'مشرف',
        };
    }

    /** هل يرى المحتوى الكامل (فجوات + توصيات + مهارات + تصدير)؟ */
    public function canViewFull(): bool
    {
        return in_array($this, [self::L3, self::EX, self::AD], true);
    }

    /** هل يرى درجات الأبعاد والرادار؟ (L2 فأعلى) */
    public function canViewDimensions(): bool
    {
        return $this->canViewFull() || $this === self::L2;
    }
}
