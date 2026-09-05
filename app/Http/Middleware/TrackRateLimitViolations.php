<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * تتبع انتهاكات Rate Limit — التصعيد ثلاثي المستويات (rate-limiting-spec §7).
 * يُطبَّق على مسارات المصادقة الحساسة (يسبق throttle حتى يرى استجابة 429).
 * العدادات في Redis مع TTL ساعة — لا جدول MySQL.
 */
class TrackRateLimitViolations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 429) {
            return $response;
        }

        // المعرف: user_id للمصادق، IP للزائر — لا يُسجَّل بريد أو كلمة مرور أبداً (§7.4)
        $key = (string) ($request->user()?->id ?? $request->ip());
        $violations = Cache::increment("rate_limit_violations:{$key}", 1, 3600);

        Log::warning('rate_limit.429', [
            'key' => $key,
            'path' => $request->path(),
            'violations' => $violations,
        ]);

        // المستوى 2: منع مؤقت — 4-7 انتهاكات في الساعة → Retry-After 120
        if ($violations >= 4) {
            Log::error('rate_limit.temporary_block', ['key' => $key, 'violations' => $violations]);
        }

        // المستوى 3: منع مطوّل — 8+ → Retry-After 600 + تنبيه مشرف
        if ($violations >= 8) {
            Log::critical('rate_limit.extended_block', ['key' => $key, 'violations' => $violations]);
            // TODO v1.1: إشعار مشرف (إلغاء الحظر اليدوي مؤجل للمواصفة §10.3)
        }

        return $response;
    }
}
