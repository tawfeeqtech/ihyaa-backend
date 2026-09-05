<?php

namespace App\Http\Requests;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T163 — التحقق من تحديث مشروع (SRS-API-16 · SRS-F02).
 * كل الحقول اختيارية (sometimes) — تحديث جزئي (contract §PUT).
 * التفويض عبر ProjectPolicy::update — بدل isOwner() في الـ controller (T162).
 */
class UpdateProjectRequest extends FormRequest
{
    /** المالك فقط (ProjectPolicy) — غير المالك 403. */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $this->user()?->can('update', $project) ?? false;
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
            'title' => ['sometimes', 'string', 'min:5', 'max:120'],
            'description' => ['sometimes', 'string', 'min:50', 'max:2000'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'status' => ['sometimes', Rule::enum(ProjectState::class)],
            'publication_status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.Project::MAX_TAGS],
            'tags.*' => ['string', 'max:50'],
            'team' => ['sometimes', 'nullable', 'array', 'max:10'],
            'team.*.name' => ['required_with:team', 'string', 'max:120'],
            'team.*.role' => ['nullable', 'string', 'max:120'],
            'github_url' => ['sometimes', 'nullable', 'url', 'max:255', 'regex:/^https?:\/\/(www\.)?github\.com\//i'],
            'video_url' => ['sometimes', 'nullable', 'url', 'max:255',
                'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be|vimeo\.com)\/.+$/i',
            ],
            'video_provider' => ['sometimes', 'nullable', Rule::in(['youtube', 'vimeo'])],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'gte:budget_min', 'max:999999999999'],
            'visibility_level' => ['sometimes', Rule::enum(VisibilityLevel::class)],
        ];
    }
}
