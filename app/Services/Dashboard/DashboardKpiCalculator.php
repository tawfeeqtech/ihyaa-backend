<?php

namespace App\Services\Dashboard;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\User;

/**
 * مؤشرات لوحتَي التحكم — US-052 (صاحب فكرة) · US-057 (مستثمر).
 * الـ Calculator واحد لكل الدورين (data-model §2.2) — كل طريقة تُختبر مباشرة.
 *
 * 4 مؤشرات:
 *  - total_projects          : عدد مشاريعه (دون المهملات).
 *  - average_score           : متوسط درجة AI للمشاريع ذات تقييم مكتمل (يستبعد
 *                              processing/failed/غير المقيَّم) — خانة عشرية واحدة.
 *  - average_score_note      : "average_score_excludes_incomplete" عند وجود مشاريع
 *                              مستبعدة من المتوسط، وإلا null.
 *  - total_requests_received : عدد طلبات الاهتمام المستلمة على مشاريعه.
 *  - accepted_requests       : عدد الطلبات المقبولة.
 *
 * تحديد "أحدث تقييم لكل مشروع" عبر joinSub على MAX(id) لكل project_id — مضمون
 * حتى عند تساوي created_at (المعرّف أعلى أولوية).
 */
class DashboardKpiCalculator
{
    public function for(User $user): array
    {
        $projects = $user->projects();
        $totalProjects = (clone $projects)->count();

        // أحدث تقييم لكل مشروع (بالمعرّف — ترتيب قطعي).
        $latestEvals = Evaluation::query()
            ->select('evaluations.*')
            ->joinSub(
                Evaluation::query()
                    ->selectRaw('MAX(id) as id')
                    ->groupBy('project_id'),
                'latest',
                'latest.id',
                '=',
                'evaluations.id',
            );

        // المشاريع المعروضة كـ "completed" (أحدث تقييم مكتمل/جزئي) — مصدر المتوسط.
        $completedProjectIds = (clone $latestEvals)
            ->whereIn('status', [EvaluationStatus::COMPLETED->value, EvaluationStatus::PARTIAL->value])
            ->pluck('project_id');

        $scored = (clone $projects)->whereIn('id', $completedProjectIds);
        $scoredCount = $scored->count();

        $average = $scored->avg('ai_score');
        $averageScore = $average === null ? null : round((float) $average, 1);

        $hasExcluded = $totalProjects > 0 && $scoredCount < $totalProjects;

        $interests = $user->interestsReceived();

        return [
            'total_projects' => $totalProjects,
            'average_score' => $averageScore,
            'average_score_note' => $hasExcluded ? 'average_score_excludes_incomplete' : null,
            'total_requests_received' => (clone $interests)->count(),
            'accepted_requests' => (clone $interests)
                ->where('interests.status', InterestStatus::ACCEPTED)
                ->count(),
        ];
    }

    /**
     * مؤشرات لوحة المستثمر (US-057 · contract §2 kpis · T084/T085).
     * تُعاد عند كل تحميل — لا caching (SRS-F11-02):
     *   sent_requests     : الطلبات المرسلة غير الملغاة.
     *   accepted_requests : الطلبات المقبولة.
     *   followed_projects : المشاريع المحفوظة (مقياس "المتابعة" — SRS F11-02).
     *
     * @return array{sent_requests:int, accepted_requests:int, followed_projects:int}
     */
    public function investorKpis(User $investor): array
    {
        $sent = $investor->interestsSent();

        return [
            'sent_requests' => (clone $sent)
                ->where('status', '!=', InterestStatus::CANCELLED->value)
                ->count(),
            'accepted_requests' => (clone $sent)
                ->where('status', InterestStatus::ACCEPTED->value)
                ->count(),
            'followed_projects' => $investor->savedProjects()->count(),
        ];
    }
}
