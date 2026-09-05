<?php

namespace App\Enums;

/**
 * نوع الاهتمام — docs/api/enums.md §1.7 (إلزامي في طلب الاهتمام).
 */
enum InterestType: string
{
    case INVESTMENT = 'investment';
    case TECHNICAL_DEVELOPMENT = 'technical_development';
    case CONSULTATION = 'consultation';

    public function label(): string
    {
        return match ($this) {
            self::INVESTMENT => 'استثمار',
            self::TECHNICAL_DEVELOPMENT => 'تطوير تقني',
            self::CONSULTATION => 'استشارة',
        };
    }
}
