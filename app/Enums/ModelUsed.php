<?php

namespace App\Enums;

/**
 * مزود AI الذي أجرى التقييم فعلياً — docs/api/enums.md §1.11.
 * openai = الأساسي · claude = الاحتياطي (Fallback — SRS-F03-03).
 */
enum ModelUsed: string
{
    case OPENAI = 'openai';
    case CLAUDE = 'claude';

    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::CLAUDE => 'Claude',
        };
    }
}
