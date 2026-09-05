<?php

namespace App\Enums;

/**
 * حالة تقييم AI — docs/api/enums.md §1.6.
 * partial: نتيجة جزئية (اكتملت 3 من 5 أبعاد ضمن سقف 180 ثانية) — تُعامل كـ completed مع تحذيرات.
 */
enum EvaluationStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case PARTIAL = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'في الانتظار',
            self::PROCESSING => 'قيد المعالجة',
            self::COMPLETED => 'مكتمل',
            self::FAILED => 'فشل',
            self::PARTIAL => 'جزئي',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED || $this === self::PARTIAL;
    }
}
