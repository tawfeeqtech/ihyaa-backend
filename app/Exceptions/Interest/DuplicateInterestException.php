<?php

namespace App\Exceptions\Interest;

/**
 * US-043 — سبق إرسال طلب نشط لنفس (investor_id, project_id).
 * 422 + كود duplicate_interest (contract §1).
 */
class DuplicateInterestException extends InterestException
{
    public function __construct(string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            code: 'duplicate_interest',
            status: 422,
            errors: $errors,
            message: $message !== '' ? $message : __('interests.duplicate_interest'),
            previous: $previous,
        );
    }
}
