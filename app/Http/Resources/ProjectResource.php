<?php

namespace App\Http\Resources;

use App\Enums\EvaluationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مورد المشروع — T161 (بدل Project::toCardArray + منطق show/projectDetail في ProjectController).
 *
 * دالة الإفصاح discloseFor(): guest/registered/owner (SRS-F05-05 — مصفوفة الإفصاح في plan.md §).
 *  - card()   : بطاقة المعرض (SRS-F07) — قائمة/بحث/لوحات.
 *  - detail() : التفاصيل الكاملة حسب الدور — show/store/update (SRS-API-14).
 *
 * تفويض: Project::toCardArray() ← card() · ProjectController show/store/update ← detail().
 */
class ProjectResource extends JsonResource
{
    /** @var Project */
    public $resource;

    /** دالة الإفصاح — guest/registered/owner (SRS-F05-05) */
    public function discloseFor(?User $viewer): string
    {
        return $this->resource->reportAccessFor($viewer);
    }

    /** بطاقة المعرض (SRS-F07) — البديل لـ Project::toCardArray() */
    public function card(?User $viewer = null): array
    {
        $project = $this->resource;

        return [
            'id' => $project->id,
            'title' => $project->title,
            'description' => $project->description,
            'category' => $project->category ? [
                'slug' => $project->category->slug,
                'name' => $project->category->name(),
            ] : null,
            'status' => $project->status->value,            // T166: status بدل state (contract §projects)
            'status_label' => $project->status->label(),
            'ai_score' => $project->ai_score,
            'evaluation_status' => $project->ai_score !== null ? 'completed' : 'pending', // T148 — US-037 س4
            'budget' => $project->budget_min !== null
                ? ['min' => $project->budget_min, 'max' => $project->budget_max]
                : null,
            'tags' => $project->tags ?? [],
            'cover_url' => $project->coverUrl(),
            'view_count' => $project->view_count,
            'visibility_level' => $project->effectiveVisibilityFor($viewer),
            'created_at' => $project->created_at?->toISOString(),
        ];
    }

    /**
     * التفاصيل الكاملة مع الإفصاح حسب الدور (SRS-API-14) — بدل projectDetail/show في ProjectController.
     *
     * @param  string|null  $access  تجاوز مستوى الوصول (المالك: 'full'). يُحسب تلقائياً عند null
     *                               عبر discloseFor()/reportAccessFor().
     */
    public function detail(?User $viewer = null, ?string $access = null): array
    {
        $project = $this->resource;
        $reportAccess = $access ?? $project->reportAccessFor($viewer);

        $latest = $project->evaluations()
            ->whereIn('status', [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL])
            ->first();

        $data = $this->card($viewer);
        $data['description'] = $project->description;
        $data['publication_status'] = $project->publication_status->value;
        $data['github_url'] = $project->github_url;
        $data['video'] = $project->video_url ? [
            'url' => $project->video_url,
            'provider' => $project->video_provider?->value,
        ] : null;
        // للمالك فقط: مستوى الرؤية المُخزّن + الفريق — لتعبئة نموذج التعديل مسبقاً
        // (visibility_level في البطاقة هو المستوى الفعّال، وليس الإعداد المخزّن).
        if ($project->isOwner($viewer)) {
            $data['stored_visibility_level'] = $project->visibility_level->value;
            $data['team'] = $project->team ?? [];
        }
        $data['files'] = $project->files->map->toArrayApi();
        $data['owner'] = $project->isOwner($viewer) || $reportAccess === 'full' ? [
            'id' => $project->owner->id,
            'name' => $project->owner->name,
            'avatar_url' => $project->owner->avatar_path ? asset('storage/'.$project->owner->avatar_path) : null,
            'email' => $reportAccess === 'full' ? $project->owner->email : null, // كشف البريد بعد الاتفاق فقط (UC-07)
        ] : null;
        $data['report_access'] = $reportAccess;
        $data['evaluation'] = $latest ? $latest->toReportArray($reportAccess) : null;

        return $data;
    }

    /** JsonResource — البطاقة الافتراضية (عند إرجاع المورد مباشرة عبر toResponse) */
    public function toArray(Request $request): array
    {
        return $this->card($request->user('sanctum'));
    }
}
