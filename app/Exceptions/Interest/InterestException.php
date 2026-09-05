<?php

namespace App\Exceptions\Interest;

use RuntimeException;
use Throwable;

/**
 * الاستثناء الأساسي لمسار الاهتمام/الاتفاق (EPIC-08).
 * يحمل كود الخطأ الموحّد + HTTP status + بيانات إضافية (redirect ...).
 * يُحوَّل إلى JSON في bootstrap/app.php عبر renderer مسجّل لهذه الفئة.
 *
 * ملاحظة تنفيذ: لا نعيد تعريف خصائص `Exception` المحمية (code/message) —
 * `$code` محجوز في Exception؛ نخزّن قيمنا في خصائص خاصة بأسماء غير متصادمة
 * ونكشفها عبر دوال code()/status()/errors()/extra().
 *
 * الأكواد (contract interest-api.md §1): duplicate_interest · profile_incomplete ·
 * project_unavailable · self_interest · invalid_type · INVALID_INTEREST_STATUS ·
 * INTEREST_CANCELLED · AGREEMENT_ACCESS_DENIED ...
 */
abstract class InterestException extends RuntimeException
{
    private string $errorCode;

    private int $errorStatus;

    private array $errorDetails;

    private array $errorExtra;

    public function __construct(
        string $code,
        int $status = 422,
        array $errors = [],
        array $extra = [],
        string $message = '',
        int $laravelCode = 0,
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $code;
        $this->errorStatus = $status;
        $this->errorDetails = $errors;
        $this->errorExtra = $extra;

        parent::__construct($message !== '' ? $message : $code, $laravelCode, $previous);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->errorStatus;
    }

    public function errors(): array
    {
        return $this->errorDetails;
    }

    public function extra(): array
    {
        return $this->errorExtra;
    }
}
