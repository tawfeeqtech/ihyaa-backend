<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تجديد توكن Sanctum — صلاحية 24 ساعة مع تجديد تلقائي عند الاستخدام (SRS-NFR-07).
 */
class RefreshSanctumToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->expires_at && $token->expires_at->isPast()) {
            // تجديد لـ 24 ساعة إضافية من آخر نشاط
            $token->forceFill(['expires_at' => now()->addHours(24)])->save();
        }

        return $next($request);
    }
}
