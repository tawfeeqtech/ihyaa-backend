<?php

namespace App\Exceptions\Ai;

use Exception;
use Throwable;

/**
 * يُرمى عند محاولة بدء تقييم لمشروع لديه تقييم `processing` نشط بالفعل
 * (قاعدة "لا تقييمان متزامنان لنفس المشروع" — data-model.md §2.4 / US-024-S4).
 */
class EvaluationInProgressException extends Exception
{
    public function __construct(
        ?int $projectId = null,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : (
                $projectId !== null
                    ? "An evaluation is already in progress for project #{$projectId}."
                    : 'An evaluation is already in progress for this project.'
            ),
            $code,
            $previous
        );
    }
}
