<?php

namespace App\Services\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\Project;
use App\Models\User;
use App\Support\ScoreFormatter;
use Illuminate\Support\Collection;

/**
 * تغذية تحديثات المستثمر — US-060 · data-model §5.2 (T099).
 *
 * لا يوجد جدول "أحداث تحديثات" في MVP — الاشتقاق عند الطلب (SRS-API-39):
 *   1) engagement set : interests غير الملغاة UNION saved_projects — الطلبات
 *      الملغاة والمحفوظات المزالة تخرج تلقائياً من النطاق (US-060/5).
 *   2) evaluation_updated : اكتمال تقييم بدرجة مختلفة عن سابقتها المكتملة
 *      (تبسيط PHP — آخر 50 تقييم مكتمل على مشاريع النطاق، اجتياز مصفوفة مرتبة).
 *   3) project_edited : updated_at > COALESCE(last_evaluation_at, created_at) —
 *      مقارنة في PHP (لا MySQL) لتفادي مشكلة دقة الثانية.
 * الدمج: فرز created_at تنازلياً وقطع عند 20 (SRS-F11-05).
 * المشاريع المحذوفة لا تصدر أحداثاً — لكن بطاقة المحفوظات تعرض
 * "هذا المشروع غير متاح حالياً" (US-059/6).
 */
class InvestorUpdatesFeedService
{
    public const DEFAULT_LIMIT = 20;      // SRS-F11-05 · contract §2

    public const EVAL_WINDOW = 50;        // data-model §5.2 — آخر 50 مكتمل

    public function __construct(private readonly SuggestionMatcher $matcher)
    {
    }

    /**
     * أحداث التحديثات الموحّدة — مصفوفات جاهزة للـ JSON (created_at بصيغة ISO).
     *
     * @return Collection<int, array{
     *   id: string,
     *   type: string,
     *   project: array{id: int, title: ?string},
     *   detail: string,
     *   old_score: int|float|null,
     *   new_score: int|float|null,
     *   created_at: string
     * }>
     */
    public function for(User $investor, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $engagedIds = $this->engagedProjectIds($investor);

        if ($engagedIds === []) {
            return collect();
        }

        $events = array_merge(
            $this->evaluationUpdates($engagedIds),
            $this->projectEdits($engagedIds),
        );

        usort($events, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return collect($events)
            ->slice(0, $limit)
            ->map(function (array $event): array {
                $event['created_at'] = $event['created_at']?->toISOString();

                return $event;
            })
            ->values();
    }

    /**
     * نطاق المشاركة — interests غير الملغاة UNION saved_projects.
     *
     * @return array<int, int>
     */
    private function engagedProjectIds(User $investor): array
    {
        $fromInterests = $investor->interestsSent()
            ->where('status', '!=', InterestStatus::CANCELLED->value)
            ->pluck('project_id')
            ->all();

        $fromSaved = $investor->savedProjects()
            ->pluck('project_id')
            ->all();

        return array_values(array_unique(array_map('intval', array_merge($fromInterests, $fromSaved))));
    }

    /**
     * أحداث "تحديث التقييم" — تفاوت الدرجة عن التقييم المكتمل السابق لنفس المشروع.
     *
     * @param  array<int, int>  $engagedIds
     * @return array<int, array>
     */
    private function evaluationUpdates(array $engagedIds): array
    {
        // آخر 50 تقييم مكتمل على مشاريع النطاق — id تنازلي ثم عكس للترتيب الزمني.
        $evaluations = Evaluation::query()
            ->with('project:id,title')
            ->whereIn('project_id', $engagedIds)
            ->where('status', EvaluationStatus::COMPLETED->value)
            ->orderByDesc('id')
            ->limit(self::EVAL_WINDOW)
            ->get()
            ->reverse()
            ->values();

        $events = [];
        $previousScore = [];      // project_id => ?float

        foreach ($evaluations as $evaluation) {
            $projectId = (int) $evaluation->project_id;
            $score = $evaluation->overall_score;

            if (array_key_exists($projectId, $previousScore)
                && (float) $score !== (float) $previousScore[$projectId]) {
                $old = ScoreFormatter::format($previousScore[$projectId]);
                $new = ScoreFormatter::format($score);

                $events[] = [
                    'id' => 'ev-'.$evaluation->id,
                    'type' => 'evaluation_updated',
                    'project' => [
                        'id' => $evaluation->project_id,
                        'title' => $evaluation->project?->title,
                    ],
                    'detail' => __('dashboard.evaluation_updated', ['old' => $old, 'new' => $new]),
                    'old_score' => $old,
                    'new_score' => $new,
                    'created_at' => $evaluation->created_at,
                ];
            }

            $previousScore[$projectId] = $score;
        }

        return $events;
    }

    /**
     * أحداث "تعديل المشروع" — updated_at أحدث من آخر تقييم (أو الإنشاء).
     *
     * @param  array<int, int>  $engagedIds
     * @return array<int, array>
     */
    private function projectEdits(array $engagedIds): array
    {
        // withoutTrashed ضمنياً — المشاريع المحذوفة لا تصدر أحداثاً (data-model §5.2).
        $projects = Project::query()
            ->whereIn('id', $engagedIds)
            ->get(['id', 'title', 'created_at', 'updated_at', 'last_evaluation_at']);

        $events = [];

        foreach ($projects as $project) {
            $anchor = $project->last_evaluation_at ?? $project->created_at;

            if ($project->updated_at && $project->updated_at->gt($anchor)) {
                $events[] = [
                    'id' => 'pr-'.$project->id,
                    'type' => 'project_edited',
                    'project' => [
                        'id' => $project->id,
                        'title' => $project->title,
                    ],
                    'detail' => __('dashboard.project_edited'),
                    'old_score' => null,
                    'new_score' => null,
                    'created_at' => $project->updated_at,
                ];
            }
        }

        return $events;
    }
}
