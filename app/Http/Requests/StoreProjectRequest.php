<?php

namespace App\Http\Requests;

use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Enums\VisibilityLevel;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T163 — التحقق من إنشاء مشروع (SRS-API-15 · SRS-F02).
 * قواعد منقولة من ProjectController::validateProject (T067/T075/T086).
 * استنتاج video_provider (T133) يتم في ProjectService::create — هنا تحقق الشكل فقط.
 */
class StoreProjectRequest extends FormRequest
{
    /**
     * الترويسة idea-owner (Route Middleware) تغلق الباب أمام غير أصحاب الأفكار —
     * هذا فحص دفاع إضافي (طبقتان — الدستور V).
     */
    public function authorize(): bool
    {
        return $this->user()?->isIdeaOwner() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:50', 'max:2000'],       // 50–2000 حرف
            'category_id' => ['required', 'exists:categories,id'],
            'status' => ['required', Rule::enum(ProjectState::class)],
            'publication_status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'tags' => ['nullable', 'array', 'max:'.Project::MAX_TAGS],
            'tags.*' => ['string', 'max:50'],
            'team' => ['nullable', 'array', 'max:10'],
            'team.*.name' => ['required_with:team', 'string', 'max:120'],
            'team.*.role' => ['nullable', 'string', 'max:120'],
            'github_url' => ['nullable', 'url', 'max:255', 'regex:/^https?:\/\/(www\.)?github\.com\//i'], // T134
            'video_url' => ['nullable', 'url', 'max:255',
                'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be|vimeo\.com)\/.+$/i', // SRS-F02-03
            ],
            'video_provider' => ['nullable', Rule::in(['youtube', 'vimeo'])], // T133 — يُستنتج من video_url إن غاب
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'budget_max' => ['nullable', 'numeric', 'gte:budget_min', 'max:999999999999'],
            'visibility_level' => ['sometimes', Rule::enum(VisibilityLevel::class)],
        ];
    }
}
