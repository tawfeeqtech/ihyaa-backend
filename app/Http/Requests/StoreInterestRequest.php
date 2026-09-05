<?php

namespace App\Http\Requests;

use App\Enums\InterestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T032 — التحقق من إرسال طلب اهتمام (US-041 · US-042 · contract §1).
 * interest_type إلزامي ∈ {investment, technical_development, consultation}
 * + message اختيارية nullable|string|max:500.
 */
class StoreInterestRequest extends FormRequest
{
    /** إرسال طلب — لأي مستخدم مصادق (يُرفض ذاتياً في الخدمة حسب الدور/الملكية). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'interest_type' => ['required', Rule::enum(InterestType::class)],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
