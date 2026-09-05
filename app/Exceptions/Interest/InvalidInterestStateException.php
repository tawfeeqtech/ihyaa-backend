<?php

namespace App\Exceptions\Interest;

/**
 * محاولة معالجة طلب في حالة لا تسمح بالانتقال (US-044 السيناريو 4/5).
 * 409 — تعارض حالة (contract §6). كود إضافي `INTEREST_CANCELLED` عندما
 * يكون الطلب ملغىً (UC-06 E3).
 */
class InvalidInterestStateException extends InterestException
{
    public function __construct(
        string $code = 'INVALID_INTEREST_STATUS',
        string $message = '',
        array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            code: $code,
            status: 409,
            errors: $errors,
            message: $message !== '' ? $message : __('interests.invalid_status'),
            previous: $previous,
        );
    }
}
