<?php

namespace App\Support\Traits;

use App\Http\Responses\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * استجابات JSON موحّدة — docs/architecture/middleware.md §3 (جدول أكواد الأخطاء).
 *
 * النجاح:  { success, message, data, ...meta }
 * الخطأ:   { success: false, code, message, errors, ...extra }
 */
trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'ok', int $status = 200, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        return response()->json(array_merge($payload, $meta), $status);
    }

    protected function created(mixed $data = null, string $message = 'created'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function accepted(mixed $data = null, string $message = 'accepted'): JsonResponse
    {
        return $this->success($data, $message, 202);
    }

    protected function noContent(string $message = 'ok'): JsonResponse
    {
        return $this->success(null, $message, 200);
    }

    /**
     * @param  string  $code  كود الخطأ الموحّد (FORBIDDEN | NOT_FOUND | INTEREST_CANCELLED ...)
     * @param  int  $status  HTTP status
     * @param  mixed  $errors  تفاصيل (Validation errors ...)
     */
    protected function error(string $code, string $message, int $status = 400, mixed $errors = null, array $extra = []): JsonResponse
    {
        // T028 — البنية الموحّدة عبر ApiError (app/Http/Responses/ApiError.php).
        return (new ApiError($code, $message, $errors, $extra))->toResponse($status);
    }

    protected function forbidden(?string $message = null): JsonResponse
    {
        return $this->error('FORBIDDEN', $message ?? __('errors.forbidden'), 403);
    }

    protected function notFound(?string $message = null): JsonResponse
    {
        return $this->error('NOT_FOUND', $message ?? __('errors.not_found'), 404);
    }

    protected function conflict(string $code, string $message): JsonResponse
    {
        return $this->error($code, $message, 409);
    }

    protected function unprocessable(string $code, string $message, mixed $errors = null): JsonResponse
    {
        return $this->error($code, $message, 422, $errors);
    }

    /** استجابة صفحة (data + meta pagination) — مع دمج meta إضافية */
    protected function paginated(LengthAwarePaginator $paginator, mixed $data = null, string $message = 'ok', array $extraMeta = []): JsonResponse
    {
        return $this->success($data ?? $paginator->items(), $message, 200, [
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ], $extraMeta),
        ]);
    }
}
