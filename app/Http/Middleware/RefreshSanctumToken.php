<?php

namespace App\Http\Middleware;

use App\Support\GeoLocationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تجديد توكن Sanctum — صلاحية 24 ساعة مع تجديد تلقائي عند الاستخدام (SRS-NFR-07)
 * وتحديث البيانات الوصفية للجلسة في حال كانت فارغة.
 */
class RefreshSanctumToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Illuminate\Database\Eloquent\Model && $token->exists) {
            $updates = [];

            if ($token->expires_at && $token->expires_at->isPast()) {
                // تجديد لـ 24 ساعة إضافية من آخر نشاط
                $updates['expires_at'] = now()->addHours(24);
            }

            if (empty($token->ip_address) && $request->ip()) {
                $updates['ip_address'] = $request->ip();
            }

            if (empty($token->user_agent) && $request->userAgent()) {
                $updates['user_agent'] = $request->userAgent();
            }

            if (empty($token->location)) {
                $updates['location'] = GeoLocationService::resolve($request);
            }

            if (!empty($updates)) {
                $token->forceFill($updates)->save();
            }
        }

        return $next($request);
    }
}
