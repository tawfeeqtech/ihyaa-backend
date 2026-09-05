<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationInputSnapshot;
use App\Models\Project;

/**
 * لقطة مدخلات التقييم — data-model.md §3 (plan.md §1.7 / FR-223 / SRS-TEST-AI-11).
 *
 * تلتقط لقطة مجرّدة (بلا بيانات حساسة: لا بريد، لا أرقام، لا أسماء أشخاص) من بيانات
 * المشروع لحظة البدء، وتُحفظ في `evaluation_input_snapshots` لأغراض التدقيق/المعايرة،
 * وتعيد مدخلات المحرك (Orchestrator) بالصيغة المطلوبة (plan.md §1.1).
 *
 * @final
 */
final class EvaluationInputSnapshotter
{
    /**
     * التقاط اللقطة وحفظها، ثم إعادة مدخلات EvaluationOrchestrator::evaluate().
     *
     * @return array<string, mixed>
     */
    public function snapshot(Project $project, Evaluation $evaluation): array
    {
        $filesMeta = $this->filesMeta($project);
        $videoMeta = $this->videoMeta($project);
        $teamMeta = $this->teamMeta($project);
        $businessInfo = $this->businessInfo($project);

        EvaluationInputSnapshot::updateOrCreate(
            ['evaluation_id' => $evaluation->id],
            [
                'project_id' => $project->id,
                'description' => $project->description,
                'github_url' => $project->github_url,
                'files_meta' => $filesMeta,
                'video_meta' => $videoMeta,
                'team_meta' => $teamMeta,
                'business_info' => $businessInfo,
            ],
        );

        $tags = $project->tags ?? [];

        return [
            'evaluation_id' => $evaluation->id,
            'project_id' => $project->id,
            'description' => $project->description,
            // MVP: لا جلب README من GitHub — engine يتحذّر ويمرر (plan.md §1.3 "README/هيكل فقط" مؤجل).
            'github_readme' => null,
            'docs_meta' => $filesMeta,
            'technologies' => $tags,
            'tags' => $tags,
            'category' => $project->category?->name_en ?? $project->category?->slug ?? null,
            'video_description' => null,   // لا استخراج وصف فيديو في MVP
            'business_info' => $businessInfo,
            'market' => null,
            'competitors' => [],
            'team' => $teamMeta,
            'roadmap' => null,
            'model_used' => null,          // يُملأ من ai_request_logs بعد التنفيذ (FR-206/207)
        ];
    }

    /**
     * وصف ملفات المشروع (معرّفات فقط — لا محتوى الملف).
     *
     * @return list<array{id: int, name: string|null, type: string|null, size: int|null, is_cover: bool}>
     */
    private function filesMeta(Project $project): array
    {
        return $project->files
            ->map(fn ($file) => [
                'id' => (int) $file->id,
                'name' => $file->original_name,
                'type' => $file->type?->value,
                'size' => $file->file_size,
                'is_cover' => (bool) $file->is_cover,
            ])
            ->values()
            ->all();
    }

    /**
     * بيانات الفيديو (المزود والرابط فقط — تُنظَّم في Sprint 1).
     *
     * @return array{provider: string|null, url: string}|null
     */
    private function videoMeta(Project $project): ?array
    {
        if ($project->video_url === null) {
            return null;
        }

        return [
            'provider' => $project->video_provider?->value,
            'url' => $project->video_url,
        ];
    }

    /**
     * بيانات الفريق من الملف الشخصي لصاحب الفكرة — بلا أسماء/بريد/أرقام (المبدأ V).
     *
     * @return list<array{role: string, skills: list<string>, experience: string|null}>
     */
    private function teamMeta(Project $project): array
    {
        $owner = $project->owner;

        if ($owner === null) {
            return [];
        }

        $skills = [];

        if ($owner->major !== null) {
            $skills[] = $owner->major;
        }

        if ($owner->university !== null) {
            $skills[] = $owner->university;
        }

        return [[
            'role' => 'owner',
            'skills' => array_values(array_unique(array_filter($skills))),
            'experience' => $owner->bio,
        ]];
    }

    /**
     * معلومات العمل — نموذج العمل/السوق/المنافسون (سوق MVP: ميزانية + قطاع + مرحلة).
     *
     * @return array{budget: array{min: float|null, max: float|null}, sector: string|null, stage: string|null}
     */
    private function businessInfo(Project $project): array
    {
        return [
            'budget' => [
                'min' => $project->budget_min !== null ? (float) $project->budget_min : null,
                'max' => $project->budget_max !== null ? (float) $project->budget_max : null,
            ],
            'sector' => $project->category?->name_en ?? $project->category?->slug ?? null,
            'stage' => $project->status?->value,
        ];
    }
}
