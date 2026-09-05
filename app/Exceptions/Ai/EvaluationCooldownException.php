<?php

namespace App\Exceptions\Ai;

use DateTimeInterface;
use Exception;
use Throwable;

/**
 * يُرمى عند محاولة إعادة تقييم خلال فترة الهدوء (24h — SRS-AI-C01/C03, US-005).
 * يحمل الوقت المتبقي بالثواني وموعد التقييم التالي لعرضه في الواجهة (US-005-S2).
 */
class EvaluationCooldownException extends Exception
{
    public function __construct(
        public readonly int $remainingSeconds,
        public readonly ?DateTimeInterface $nextAllowedAt = null,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Evaluation is in cooldown. Next evaluation allowed in %d second(s).',
                $remainingSeconds
            ),
            $code,
            $previous
        );
    }

    public function remainingSeconds(): int
    {
        return $this->remainingSeconds;
    }

    public function nextAllowedAt(): ?DateTimeInterface
    {
        return $this->nextAllowedAt;
    }
}
