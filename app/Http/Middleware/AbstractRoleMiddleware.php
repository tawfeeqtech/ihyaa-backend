<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * قاعدة وسائط الأدوار الثلاثة — docs/architecture/middleware.md §3.
 * 401 UNAUTHENTICATED · 409 ROLE_REQUIRED (أول دخول OAuth) · 403 FORBIDDEN
 */
abstract class AbstractRoleMiddleware
{
    abstract protected function role(): UserRole;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException;
        }

        // أول دخول OAuth قبل اختيار الدور (SRS-F01-07) — يخبر الواجهة بفتح شاشة اختيار الدور
        if ($user->role === null) {
            return response()->json([
                'success' => false,
                'code' => 'ROLE_REQUIRED',
                'message' => __('auth.role_required'),
                'errors' => null,
            ], 409);
        }

        abort_unless($user->role === $this->role(), 403, __('auth.forbidden'));

        return $next($request);
    }
}
