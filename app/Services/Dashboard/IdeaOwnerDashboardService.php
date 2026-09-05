<?php

namespace App\Services\Dashboard;

use App\Http\Resources\Dashboard\ProjectMiniCardResource;
use App\Models\Project;
use App\Models\User;

/**
 * تجميع لوحة صاحب الفكرة (US-051 · T050) — dashboard-api.md §1.
 *
 * يبني الاستجابة الكاملة: kpis + مشاريع (بطاقات مصغرة بحالة AI رباعية) + feed.
 * يُحسب عند كل تحميل — لا caching (SRS-F10-01..03 · US-051 س2).
 */
class IdeaOwnerDashboardService
{
    public function __construct(
        private readonly DashboardKpiCalculator $kpis,
        private readonly OwnerEventsFeedService $feed,
    ) {
    }

    public function dataFor(User $user): array
    {
        $projects = $user->projects()
            ->with(['category', 'evaluationHistory', 'files' => fn ($q) => $q->where('type', 'image')])
            ->orderByDesc('updated_at')
            ->get();

        return [
            'kpis' => $this->kpis->for($user),
            'projects' => $projects
                ->map(fn (Project $p) => ProjectMiniCardResource::make($p)->toArray(request()))
                ->values(),
            'feed' => $this->feed->recentFor($user),
        ];
    }
}
