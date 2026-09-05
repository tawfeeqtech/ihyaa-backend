<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T163 — التحقق من تسجيل الدخول (SRS-API-02 · RL-AUTH-02).
 * نقل القواعد من AuthController::login — بدون تغيير في السلوك.
 */
class LoginRequest extends FormRequest
{
    /** دخول عام — مسار عام (L1). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ];
    }
}
