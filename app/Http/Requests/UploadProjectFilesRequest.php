<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * T163 — التحقق من رفع ملفات المشروع (SRS-API-18 · SRS-F02-02).
 *
 * T167: شكل الرفع = images[] + pdfs[] منفصلان (الحدود المركزية في config/uploads.php).
 * العقد (project-api.md §files) يحمل files[] موحّداً — القرار المعتمد هنا:
 * تثبيت الفصل images+pdfs في العقد والواجهة (أكثر وضوحاً وقابلية للفحص).
 *
 * التفويض عبر ProjectPolicy::files — بدل isOwner() في الـ controller (T162).
 */
class UploadProjectFilesRequest extends FormRequest
{
    /** المالك فقط (ProjectPolicy) — غير المالك 403. */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user()?->can('files', $project) ?? false;
    }

    /** رسالة عربية موحّدة (Arabic-first — الدستور). */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('errors.forbidden'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'images' => ['nullable', 'array', 'max:'.config('uploads.images.count')],
            'images.*' => ['image', 'mimes:'.implode(',', config('uploads.images.mimes')), 'max:'.config('uploads.images.max_kb')],
            'pdfs' => ['nullable', 'array', 'max:'.config('uploads.pdfs.count')],
            'pdfs.*' => ['file', 'mimes:'.implode(',', config('uploads.pdfs.mimes')), 'max:'.config('uploads.pdfs.max_kb')],
        ];
    }
}
