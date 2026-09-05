<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * T028 — غلاف خطأ JSON الموحّد لمسار /api: { success:false, code, message, errors, ...extra }.
 *
 * بنية واحدة لكل أخطاء الـ API — docs/architecture/middleware.md §3 (جدول أكواد الأخطاء).
 * يستخدمه معالج الاستثناءات (app/Exceptions/Handler.php) وكل المتحكمات عبر
 * ApiResponse::error(). الرسائل آمنة (لا تفاصيل داخلية — الدستور §V).
 */
final class ApiError
{
    /**
     * @param  string  $code    كود الخطأ الموحّد (COOLDOWN_ACTIVE | SEARCH_UNAVAILABLE | ...)
     * @param  string  $message رسالة آمنة للمستخدم (لا تفاصيل داخلية/مكدس)
     * @param  mixed   $errors  تفاصيل إضافية (Validation errors...) أو null
     * @param  array<string, mixed> $extra حقول إضافية (retry_after | reset_at ...)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly mixed $errors = null,
        public readonly array $extra = [],
    ) {
    }

    public function toResponse(int $status, array $headers = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'code' => $this->code,
            'message' => $this->message,
            'errors' => $this->errors,
        ], $this->extra), $status, $headers);
    }
}
