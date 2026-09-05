<?php

namespace App\Enums;

/**
 * نوع تحليل وكيل AI — docs/api/enums.md §1.10 (SRS-API-42..43).
 */
enum AnalysisType: string
{
    case COMPETITIVE = 'competitive';
    case SWOT = 'swot';
    case MARKET = 'market';
    case COMPARISON = 'comparison';

    public function label(): string
    {
        return match ($this) {
            self::COMPETITIVE => 'تقرير تنافسي',
            self::SWOT => 'تحليل SWOT',
            self::MARKET => 'تقرير سوقي',
            self::COMPARISON => 'مقارنة مشاريع',
        };
    }
}
