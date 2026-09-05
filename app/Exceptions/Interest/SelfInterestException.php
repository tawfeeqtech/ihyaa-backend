<?php

namespace App\Exceptions\Interest;

/**
 * المتصل هو مالك المشروع — لا يمكن إبداء الاهتمام بمشروعه (contract §1).
 * 422 + كود self_interest.
 */
class SelfInterestException extends InterestException
{
    public function __construct(string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            code: 'self_interest',
            status: 422,
            errors: $errors,
            message: $message !== '' ? $message : __('interests.own_project'),
            previous: $previous,
        );
    }
}
