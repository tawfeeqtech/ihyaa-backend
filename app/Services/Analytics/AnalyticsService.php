<?php

namespace App\Services\Analytics;

use App\Enums\InterestStatus;
use App\Enums\ProjectState;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;

/**
 * EPIC-12 · US-061/062/063 — لوحة تحليلات المشرف.
 *
 * مبدأ البيانات (admin-api.md §0): كل الأرقام تُحسب مباشرة من قاعدة البيانات
 * عند الطلب (تحديث عند تحميل الصفحة — لا WebSocket، لا مخازن مسبقة).
 * استعلامات COUNT/GROUP مجمّعة بفهارس داعمة (users(role)، users(last_active_at)،
 * projects(category)، projects(ai_score)، interests(status)) — p95 < 500ms.
 */
class AnalyticsService
{
    public function __construct(
        private readonly ActiveUsersReport $activeUsers,
    ) {}

    /**
     * تجميع كامل للوحة — بنية مطابقة لعقد admin-api.md §1.
     *
     * @return array<string, mixed>
     */
    public function analytics(): array
    {
        $usersTotal = User::query()->count();

        $usersByRole = User::query()
            ->selectRaw('role, COUNT(*) AS count')
            ->whereNotNull('role')
            ->groupBy('role')
            ->pluck('count', 'role');

        // المشاريع: SoftDeletes — Project::query() يستبعد المحذوفة تلقائياً.
        $projectsActive = Project::query()->count();             // deleted_at IS NULL
        $projectsTrashed = Project::query()->onlyTrashed()->count();
        $projectsTotal = $projectsActive + $projectsTrashed;

        $projectsByStatus = Project::query()
            ->selectRaw('status, COUNT(*) AS count')
            ->whereNotNull('status')
            ->groupBy('status')
            ->pluck('count', 'status');

        $avgAiScore = round((float) Project::query()->whereNotNull('ai_score')->avg('ai_score'), 2);

        // التوزيع حسب المجال — المشاريع النشطة فقط (deleted_at IS NULL).
        $sectorRows = Category::query()
            ->selectRaw('categories.name_ar AS name_ar, COUNT(projects.id) AS count')
            ->leftJoin('projects', 'projects.category_id', '=', 'categories.id')
            ->whereNull('projects.deleted_at')
            ->groupBy('categories.id', 'categories.name_ar')
            ->orderByDesc('count')
            ->get();

        $sectorDistribution = $sectorRows
            ->filter(fn ($row) => (int) $row->count > 0)
            ->map(fn ($row) => [
                'category' => $row->name_ar,
                'count' => (int) $row->count,
                'percentage' => $projectsActive > 0
                    ? round(((int) $row->count / $projectsActive) * 100, 1)
                    : 0.0,
            ])
            ->values()
            ->all();

        $interests = Interest::query()
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $interestsTotal = Interest::query()->count();

        // accepted_pending_document حالة وسيطة تنتقل إلى accepted (FR-310) —
        // تُدمج ضمن "accepted" في التقرير (عقد admin-api.md §1 لا يفردها).

        $activeUsers = $this->activeUsers->last7Days();

        return [
            'generated_at' => now()->toISOString(),
            'users' => [
                'total' => $usersTotal,
                'by_role' => [
                    UserRole::IDEA_OWNER->value => (int) ($usersByRole[UserRole::IDEA_OWNER->value] ?? 0),
                    UserRole::INVESTOR->value => (int) ($usersByRole[UserRole::INVESTOR->value] ?? 0),
                    UserRole::ADMIN->value => (int) ($usersByRole[UserRole::ADMIN->value] ?? 0),
                ],
            ],
            'projects' => [
                'total' => $projectsTotal,
                'active' => $projectsActive,
                'trashed' => $projectsTrashed,
                'by_project_status' => [
                    ProjectState::COMPLETED->value => (int) ($projectsByStatus[ProjectState::COMPLETED->value] ?? 0),
                    ProjectState::NEEDS_DEVELOPMENT->value => (int) ($projectsByStatus[ProjectState::NEEDS_DEVELOPMENT->value] ?? 0),
                    ProjectState::NEEDS_FUNDING->value => (int) ($projectsByStatus[ProjectState::NEEDS_FUNDING->value] ?? 0),
                ],
            ],
            'avg_ai_score' => $avgAiScore,
            'sector_distribution' => $sectorDistribution,
            'active_users_7d' => $activeUsers,
            'interests' => [
                'total' => $interestsTotal,
                'pending' => (int) ($interests[InterestStatus::PENDING->value] ?? 0),
                'accepted' => (int) ($interests[InterestStatus::ACCEPTED->value] ?? 0)
                    + (int) ($interests[InterestStatus::ACCEPTED_PENDING_DOCUMENT->value] ?? 0),
                'rejected' => (int) ($interests[InterestStatus::REJECTED->value] ?? 0),
                'cancelled' => (int) ($interests[InterestStatus::CANCELLED->value] ?? 0),
            ],
            'chart_sufficient' => [
                'sector' => count(array_filter($sectorDistribution, fn (array $s) => $s['count'] > 0)) >= 2,
                'active_users' => collect($activeUsers)->contains(fn (array $row) => $row['count'] > 0),
            ],
        ];
    }
}
