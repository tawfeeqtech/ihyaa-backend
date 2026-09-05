<?php

namespace App\Enums;

/**
 * نوع ملف المشروع — docs/api/enums.md §1.8.
 * الحدود: صور 5 × 5MB · PDF 3 × 10MB — تُفرض على مستوى الطلب (SRS-F02-02).
 * document: محجوز مستقبلاً — غير مقبول في MVP.
 */
enum FileType: string
{
    case IMAGE = 'image';
    case PDF = 'pdf';
    case DOCUMENT = 'document';

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'صورة',
            self::PDF => 'مستند PDF',
            self::DOCUMENT => 'مستند',
        };
    }
}
