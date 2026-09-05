<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الملف الشخصي — SRS-API-09..11 · L3/L4 (Shared — قراءة حسب الدور).
 * يُسمح بحقل role فقط عندما role=null (أول دخول OAuth — SRS-F01-07).
 */
class ProfileController
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->success($request->user()->toApiArray());
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1000'],
            // الدور: مرة واحدة فقط عند أول دخول OAuth
            'role' => ['sometimes', 'nullable', Rule::enum(UserRole::class)],
            // صاحب فكرة
            'university' => ['sometimes', 'nullable', 'string', 'max:190'],
            'major' => ['sometimes', 'nullable', 'string', 'max:190'],
            // مستثمر
            'investment_focus' => ['sometimes', 'nullable', 'string', 'max:190'],
            'investment_range' => ['sometimes', 'nullable', 'array', 'min:1', 'max:2'],
            'investment_range.min' => ['nullable', 'numeric', 'min:0'],
            'investment_range.max' => ['nullable', 'numeric', 'gte:investment_range.min'],
            'preferred_sectors' => ['sometimes', 'nullable', 'array', 'max:10'],
            'preferred_sectors.*' => ['string', 'max:100'],
        ]);

        // تعيين الدور — مرة واحدة فقط (أول دخول OAuth)
        if (array_key_exists('role', $data)) {
            if ($user->role !== null) {
                return $this->error('ROLE_ALREADY_SET', __('profile.role_already_set'), 409);
            }

            $role = $data['role'] !== null ? UserRole::from($data['role']) : null;

            if ($role === null) {
                return $this->error('VALIDATION_FAILED', __('profile.role_required'), 422);
            }

            if ($role->isAdmin()) {
                return $this->error('FORBIDDEN', __('auth.admin_registration_forbidden'), 403);
            }

            $user->setRole($role);
            unset($data['role']);
        }

        $user->fill($data)->save();

        return $this->success($user->fresh()->toApiArray(), __('profile.updated'));
    }

    /** رفع الصورة الشخصية — صورة واحدة حتى 2MB (RL-IO-03/RL-INV-03) */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:'.implode(',', config('uploads.avatar.mimes')),
                'max:'.config('uploads.avatar.max_kb'),
            ],
        ]);

        $user = $request->user();

        $path = $data['avatar']->store('avatars', 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        return $this->success([
            'avatar_url' => asset('storage/'.$path),
        ], __('profile.avatar_updated'));
    }

    /** ملف عام (L2 — SRS-API-12) — لا بريد ولا بيانات حساسة · T161: ProfileResource */
    public function showPublic(User $user): JsonResponse
    {
        return $this->success(ProfileResource::make($user)->resolve());
    }
}
