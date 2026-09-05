<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T102 — تحليل مشروع عبر وكيل AI (US-080..084 · SRS-API-42).
 * analysis_type إلزامية ∈ {comparison, swot, competitive} · language اختيارية ar|en.
 */
class AnalyzeProjectRequest extends FormRequest
{
    /** التصريح يتم في الـ Controller (المالك فقط — 403) */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'analysis_type' => ['required', Rule::in(['comparison', 'swot', 'competitive'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ];
    }
}
