<?php

namespace App\Exceptions\Interest;

/**
 * UC-06 A1 — ملف المستثمر ناقص الحقول الإلزامية.
 * 422 + كود profile_incomplete + redirect: /profile/edit (contract §1).
 * لا يُنشأ طلب.
 */
class ProfileIncompleteException extends InterestException
{
    public function __construct(string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            code: 'profile_incomplete',
            status: 422,
            errors: $errors,
            extra: ['redirect' => '/profile/edit'],
            message: $message !== '' ? $message : __('interests.profile_incomplete'),
            previous: $previous,
        );
    }
}
