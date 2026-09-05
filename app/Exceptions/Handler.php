<?php

namespace App\Exceptions;

use App\Exceptions\Ai\AllProvidersFailedException;
use App\Exceptions\Ai\EvaluationCooldownException;
use App\Exceptions\Ai\EvaluationInProgressException;
use App\Exceptions\Ai\EvaluationNotFailedException;
use App\Exceptions\Interest\InterestException;
use App\Exceptions\Search\SearchUnavailableException;
use App\Http\Responses\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * T028 — رسم الاستثناءات إلى غلاف خطأ JSON الموحّد { success:false, code, message, errors, ... }.
 *
 * يُستدعى من bootstrap/app.php عبر Handler::register($exceptions) — يوحّد كل أخطاء
 * /api تحت بنية واحدة (docs/architecture/middleware.md §3) مع أكواد محددة من العقود:
 * RATE_LIMIT_EXCEEDED · VALIDATION_FAILED · UNAUTHENTICATED · NOT_FOUND · FORBIDDEN ·
 * COOLDOWN_ACTIVE · EVALUATION_IN_PROGRESS · NOT_FAILED · AI_PROVIDERS_FAILED ·
 * SEARCH_UNAVAILABLE · وأكواد InterestException.
 *
 * الترتيب مهم: تُسجَّل الحالات المتخصصة (NotFoundHttpException، TooManyRequests…)
 * قبل HttpException العام (403) حتى لا يلتقطها الفرع العام.
 */
class Handler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // 429 موحّد لكل نقاط /api (يغطي أي limiter) — RATE_LIMIT_EXCEEDED.
        $exceptions->renderable(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $e->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);

            return (new ApiError(
                'RATE_LIMIT_EXCEEDED',
                __('rate_limit.exceeded', ['seconds' => $retryAfter]),
                null,
                [
                    'retry_after' => $retryAfter,
                    'reset_at' => (int) ($headers['X-RateLimit-Reset'] ?? now()->addSeconds($retryAfter)->timestamp),
                ],
            ))->toResponse(429, $headers);
        });

        // EPIC-08: استثناءات الاهتمام/الاتفاق — كود/حالة/أخطاء/إضافات من الاستثناء نفسه.
        $exceptions->renderable(function (InterestException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                $e->code(),
                $e->getMessage(),
                $e->errors() ?: null,
                $e->extra(),
            ))->toResponse($e->status());
        });

        // 429 COOLDOWN_ACTIVE — فترة الهدوء 24h (SRS-AI-C01/C03).
        $exceptions->renderable(function (EvaluationCooldownException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'COOLDOWN_ACTIVE',
                $e->getMessage(),
                null,
                [
                    'retry_after_seconds' => $e->remainingSeconds(),
                    'next_evaluation_at' => $e->nextAllowedAt()?->format(DATE_ATOM),
                ],
            ))->toResponse(429, ['Retry-After' => (string) $e->remainingSeconds()]);
        });

        // 409 EVALUATION_IN_PROGRESS — لا تقييمان متزامنان لنفس المشروع (US-024-S4).
        $exceptions->renderable(function (EvaluationInProgressException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'EVALUATION_IN_PROGRESS',
                $e->getMessage(),
            ))->toResponse(409);
        });

        // 422 NOT_FAILED — إعادة المحاولة لغير فاشل (US-019).
        $exceptions->renderable(function (EvaluationNotFailedException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'NOT_FAILED',
                $e->getMessage(),
                null,
                ['current_status' => $e->currentStatus()],
            ))->toResponse(422);
        });

        // 503 AI_PROVIDERS_FAILED — استُنفدت كل المحاولات على المزودين (FR-222).
        $exceptions->renderable(function (AllProvidersFailedException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'AI_PROVIDERS_FAILED',
                $e->getMessage(),
                null,
                ['retryable' => true],
            ))->toResponse(503);
        });

        // 503 SEARCH_UNAVAILABLE — Meilisearch معطّل (SRS-UI-28 · search-api.md §1).
        $exceptions->renderable(function (SearchUnavailableException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'SEARCH_UNAVAILABLE',
                __('search.unavailable'),
                null,
                ['retryable' => true],
            ))->toResponse(503);
        });

        // 422 موحّدة مع كود VALIDATION_FAILED.
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'VALIDATION_FAILED',
                $e->getMessage(),
                $e->errors(),
            ))->toResponse(422);
        });

        // 401 موحّدة — لا توكن أو منتهٍ.
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'UNAUTHENTICATED',
                __('auth.unauthenticated'),
            ))->toResponse(401);
        });

        // 404 موحّدة — ModelNotFoundException تُحوَّل إلى NotFoundHttpException في
        // prepareException (لذلك نلتقط NotFoundHttpException لا ModelNotFoundException).
        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiError(
                'NOT_FOUND',
                __('errors.not_found'),
            ))->toResponse(404);
        });

        // 403 موحّدة (abort / AccessDeniedHttpException — كود FORBIDDEN).
        // تُسجَّل أخيراً حتى لا تلتقط الحالات المتخصصة أعلاه.
        $exceptions->renderable(function (HttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 403) {
                return (new ApiError(
                    'FORBIDDEN',
                    $e->getMessage() ?: __('errors.forbidden'),
                ))->toResponse(403);
            }

            return null;
        });
    }
}
