<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvaluationStatus;
use App\Enums\InterestStatus;
use App\Models\Evaluation;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\IdeaOwnerDashboardService;
use App\Services\Dashboard\InvestorDashboardService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحات التحكم — SRS-API-38/39 · 60/دقيقة (throttle:dashboard · dashboard-api.md §0).
 * idea-owner: kpis + مشاريع (بطاقات مصغرة بحالة AI) + آخر 10 أحداث (US-051..053).
 * investor: المحفوظات + الطلبات المرسلة + مشاريع مقترحة (حتى 10).
 */
class DashboardController
{
    use ApiResponse;

    public function __construct(
        private readonly IdeaOwnerDashboardService $dashboard,
        private readonly InvestorDashboardService $investorDashboard,
    ) {
    }

    /** RL-IO-09 · 60/دقيقة — dashboard-api.md §1 */
    public function ideaOwner(Request $request): JsonResponse
    {
        return $this->success(
            $this->dashboard->dataFor($request->user()),
        );
    }

    /** RL-INV-09 · 20/دقيقة — dashboard-api.md §2 (US-057..060) */
    public function investor(Request $request): JsonResponse
    {
        return $this->success(
            $this->investorDashboard->dataFor($request->user()),
        );
    }

    /** لوحة المشرف — SRS-API-40 (تُستخدم من AdminController) */
    public function adminStats(): array
    {
        $users = User::query();
        $projects = Project::query();
        $evaluations = Evaluation::query();

        return [
            'users' => [
                'total' => (clone $users)->count(),
                'idea_owners' => (clone $users)->where('role', 'idea_owner')->count(),
                'investors' => (clone $users)->where('role', 'investor')->count(),
                'admins' => (clone $users)->where('role', 'admin')->count(),
                'active_7d' => (clone $users)->where('last_login_at', '>=', now()->subDays(7))->count(), // SRS-F12-02
            ],
            'projects' => [
                'total' => (clone $projects)->count(),
                'published' => (clone $projects)->where('publication_status', 'published')->count(),
                'drafts' => (clone $projects)->where('publication_status', 'draft')->count(),
                'trashed' => (clone $projects)->onlyTrashed()->count(),
                'avg_ai_score' => round((float) (clone $projects)->avg('ai_score'), 2),
                'by_state' => [
                    'completed' => (clone $projects)->where('status', 'completed')->count(),
                    'needs_development' => (clone $projects)->where('status', 'needs_development')->count(),
                    'needs_funding' => (clone $projects)->where('status', 'needs_funding')->count(),
                ],
                'by_category' => Category::query()
                    ->withCount('projects')
                    ->orderByDesc('projects_count')
                    ->get()
                    ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name(), 'count' => $c->projects_count]),
            ],
            'evaluations' => [
                'total' => (clone $evaluations)->count(),
                'completed' => (clone $evaluations)->whereIn('status', [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL])->count(),
                'processing' => (clone $evaluations)->where('status', EvaluationStatus::PROCESSING)->count(),
                'failed' => (clone $evaluations)->where('status', EvaluationStatus::FAILED)->count(),
                'avg_processing_time_ms' => round((float) (clone $evaluations)->avg('processing_time_ms'), 0),
            ],
            'interests' => [
                'total' => Interest::query()->count(),
                'pending' => Interest::query()->where('status', InterestStatus::PENDING)->count(),
                'accepted' => Interest::query()->where('status', InterestStatus::ACCEPTED)->count(),
            ],
        ];
    }
}
