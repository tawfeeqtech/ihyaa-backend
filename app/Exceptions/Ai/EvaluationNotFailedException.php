<?php

namespace App\Exceptions\Ai;

use Exception;
use Throwable;

/**
 * يُرمى عند محاولة إعادة محاولة (Retry — US-019) تقييم ليس في حالة `failed` (أو `partial`).
 * حالة `failed` فقط (والجزئي عبر مسار "إكمال الناقص") تملك زر إعادة المحاولة.
 */
class EvaluationNotFailedException extends Exception
{
    public function __construct(
        public readonly string $currentStatus,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Cannot retry an evaluation in status [%s]; only failed evaluations are retryable.',
                $currentStatus
            ),
            $code,
            $previous
        );
    }

    public function currentStatus(): string
    {
        return $this->currentStatus;
    }
}
