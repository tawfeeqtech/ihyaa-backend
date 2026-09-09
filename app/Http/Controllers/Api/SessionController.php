<?php

namespace App\Http\Controllers\Api;

use App\Models\PersonalAccessToken;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController
{
    use ApiResponse;

    /**
     * عرض جميع جلسات المستخدم الحالية مرتبة من الأحدث نشاطاً.
     *
     * GET /api/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        // جلب كافة التوكنات التابعة للمستخدم مرتبة بالأحدث نشاطاً
        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        $sessions = $tokens->map(function ($token) use ($currentToken) {
            /** @var PersonalAccessToken $token */
            return $token->toSessionArray($currentToken);
        })->values();

        return $this->success($sessions);
    }

    /**
     * إنهاء جلسة معينة بالمعرف الخاص بها.
     *
     * DELETE /api/sessions/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // التأكد من أن الجلسة تتبع المستخدم الحالي حصراً
        $token = $user->tokens()->where('id', $id)->first();

        if (! $token) {
            return $this->notFound(__('auth.session_not_found'));
        }

        $token->delete();

        return $this->noContent(__('auth.session_terminated'));
    }
}
