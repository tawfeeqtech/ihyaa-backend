<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * البريد غير المفعل → 403 EMAIL_NOT_VERIFIED (SRS-F01-02).
 * يُطبَّق على النقاط الحساسة (رفع مشروع، إبداء اهتمام، AI) — لا على التصفح العام.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->email_verified_at === null) {
            return response()->json([
                'success' => false,
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => __('auth.email_not_verified'),
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
