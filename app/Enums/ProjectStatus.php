<?php

namespace App\Enums;

/**
 * حالة النشر (Publication State) — docs/api/enums.md §1.2.
 * draft: مرئي لمالكها فقط · published: في المعرض · archived: مخفي من المعرض (يُحفظ كاملاً).
 */
enum ProjectStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'مسودة',
            self::PUBLISHED => 'منشور',
            self::ARCHIVED => 'مؤرشف',
        };
    }
}
