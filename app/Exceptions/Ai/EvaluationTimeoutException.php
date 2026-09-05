<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * انقضاء الميزانية الزمنية للتقييم (سقف 180s — plan.md §1.3 · SRS-AI-P02).
 * يُرمى من ConcurrentDispatcher عند امتلاء الميزانية قبل تسوية كل المهام —
 * يترجمه runEvaluation إلى حالة failed مع error_log (SRS-AI-F04).
 */
class EvaluationTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly int $ceilingSeconds,
        public readonly float $elapsedSeconds,
    ) {
        parent::__construct(sprintf(
            'Evaluation time budget exceeded: %.1f seconds elapsed (ceiling %d seconds).',
            $elapsedSeconds,
            $ceilingSeconds,
        ));
    }
}
