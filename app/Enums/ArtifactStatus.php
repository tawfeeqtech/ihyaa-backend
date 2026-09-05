<?php

namespace App\Enums;

/**
 * حالة مخرجات وكيل AI — EPIC-15 (T103/T104/T122).
 * processing → completed | failed — المسار غير المتزامن (AnalyzeProjectJob).
 */
enum ArtifactStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PROCESSING => 'قيد المعالجة',
            self::COMPLETED => 'مكتمل',
            self::FAILED => 'فشل',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
