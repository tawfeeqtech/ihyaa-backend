<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T163 — التحقق من تسجيل مستخدم جديد (SRS-API-01 · RL-AUTH-01).
 * نقل القواعد من AuthController::register — بدون تغيير في السلوك.
 */
class RegisterRequest extends FormRequest
{
    /** تسجيل عام — مسار عام (L1). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::enum(UserRole::class)],
            // ملف صاحب الفكرة
            'university' => ['nullable', 'string', 'max:190'],
            'major' => ['nullable', 'string', 'max:190'],
            // ملف المستثمر
            'investment_focus' => ['nullable', 'string', 'max:190'],
            'investment_range' => ['nullable', 'array', 'min:1', 'max:2'],
            'investment_range.min' => ['nullable', 'numeric', 'min:0'],
            'investment_range.max' => ['nullable', 'numeric', 'gte:investment_range.min'],
            'preferred_sectors' => ['nullable', 'array', 'max:10'],
            'preferred_sectors.*' => ['string', 'max:100'],
        ];
    }
}
