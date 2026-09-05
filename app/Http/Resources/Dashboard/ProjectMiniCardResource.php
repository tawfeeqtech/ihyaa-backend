<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\EvaluationStatus;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بطاقة المشروع المصغرة للوحة صاحب الفكرة (T027 · US-051) — dashboard-api.md §1.
 *
 * حالة AI رباعية (الدستور II — لا بطاقة بلا حالة AI):
 *  - completed : الدرجة ai_score بارزة (0-100).
 *  - processing: "جاري التقييم".
 *  - failed    : "فشل التقييم" + رابط retry (SRS-AI-E03).
 *  - null      : "غير مقيَّم".
 *
 * الحالة تُحدَّد من أحدث تقييم (بالمعرّف — ترتيب قطعي). العلاقة evaluationHistory
 * تُحمَّل مسبقاً في الخدمة (لا N+1)؛ عند غيابها يُستعلم مباشرة.
 */
class ProjectMiniCardResource extends JsonResource
{
    /** @var Project */
    public $resource;

    public function toArray(Request $request): array
    {
        $project = $this->resource;

        $latest = $project->relationLoaded('evaluationHistory')
            ? $project->evaluationHistory->sortByDesc('id')->first()
            : $project->evaluationHistory()->latest('id')->first();

        $evaluationStatus = match ($latest?->status) {
            EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL => 'completed',
            EvaluationStatus::PROCESSING => 'processing',
            EvaluationStatus::FAILED => 'failed',
            default => null,
        };

        // الدرجة: ai_score المخزَّن (الكاش) وإلا درجة أحدث تقييم مكتمل (أمان لبيانات قديمة).
        $score = $project->ai_score;
        if ($score === null && $latest && in_array($latest->status, [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL], true)) {
            $score = $latest->overall_score;
        }

        return [
            'id' => $project->id,
            'title' => $project->title,
            'category' => $project->category?->name(),
            'status' => $project->status->value,
            'budget_min' => $project->budget_min,
            'budget_max' => $project->budget_max,
            'cover_image_url' => $project->coverUrl(),
            'ai_score' => $score !== null ? round((float) $score, 1) : null,
            'evaluation_status' => $evaluationStatus,
            'last_evaluation_at' => $latest?->created_at?->toISOString(),
        ];
    }
}
