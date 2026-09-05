<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يسمح فقط للمستخدمين الذين لم يثبتوا دورهم بعد (role = null — أول دخول OAuth).
 * يُستخدم مع POST /auth/{provider}/role (SRS-F01-07).
 * 401 UNAUTHENTICATED · 409 ROLE_ALREADY_SET (دور مثبت مسبقاً)
 */
class PendingRoleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException;
        }

        if ($user->role !== null) {
            return response()->json([
                'success' => false,
                'code' => 'ROLE_ALREADY_SET',
                'message' => __('profile.role_already_set'),
                'errors' => null,
            ], 409);
        }

        return $next($request);
    }
}
