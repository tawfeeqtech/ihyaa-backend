<?php

namespace App\Exceptions\Interest;

/**
 * UC-06 E2 — المشروع محذوف (Soft Deleted) أو غير موجود.
 * 422 + كود project_unavailable (contract §1).
 */
class ProjectUnavailableException extends InterestException
{
    public function __construct(string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            code: 'project_unavailable',
            status: 422,
            errors: $errors,
            message: $message !== '' ? $message : __('interests.project_unavailable'),
            previous: $previous,
        );
    }
}
