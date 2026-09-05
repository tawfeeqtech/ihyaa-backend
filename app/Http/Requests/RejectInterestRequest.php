<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * T044 — التحقق من رفض طلب اهتمام (US-044 · contract §6).
 * rejection_reason اختيارية nullable|string|max:500.
 */
class RejectInterestRequest extends FormRequest
{
    /** رفض — يصرَّح به في الـ Policy (مالك المشروع فقط). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
