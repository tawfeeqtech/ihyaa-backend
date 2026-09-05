<?php

namespace App\Services\Dashboard;

use App\Enums\InterestStatus;
use App\Http\Resources\SuggestionResource;
use App\Models\Interest;
use App\Models\SavedProject;
use App\Models\User;
use App\Services\ProfileCompletenessService;
use App\Support\ScoreFormatter;

/**
 * تجميع لوحة المستثمر — US-057..060 · contract §2 (SRS-API-39) · T081/T085/T100.
 *
 * الـ Controller رفيع — كل الحمولة هنا. يُحسب عند كل تحميل (لا caching — SRS-F11-02):
 *   kpis, profile_complete, suggestions (≤10), sent_interests, saved_projects,
 *   updates_feed (≤20). القيم تُنسَّق للعرض (ScoreFormatter — لا "62.0").
 */
class InvestorDashboardService
{
    public function __construct(
        private readonly DashboardKpiCalculator $kpiCalculator,
        private readonly SuggestionMatcher $matcher,
        private readonly InvestorUpdatesFeedService $updatesFeed,
        private readonly ProfileCompletenessService $profileCompleteness,
    ) {
    }

    /**
     * @return array{
     *   kpis: array,
     *   profile_complete: bool,
     *   suggestions: array,
     *   sent_interests: array,
     *   saved_projects: array,
     *   updates_feed: array
     * }
     */
    public function dataFor(User $investor): array
    {
        $suggestions = $this->matcher->match($investor);
        $badges = $this->matcher->engagementBadges($investor);

        return [
            'kpis' => $this->kpiCalculator->investorKpis($investor),
            'profile_complete' => $this->profileCompleteness->isInvestorComplete($investor),
            'suggestions' => $suggestions
                ->map(fn ($project) => SuggestionResource::make($project, $badges[$project->id] ?? null))
                ->values()
                ->all(),
            'sent_interests' => $this->sentInterests($investor),
            'saved_projects' => $this->savedProjects($investor),
            'updates_feed' => $this->updatesFeed->for($investor)->all(),
        ];
    }

    /**
     * الطلبات المرسلة — بحالاتها (الأحدث أولاً).
     * can_cancel: pending فقط · agreement_available: مقبول مع PDF · agreement_url: /api/agreements/{id}.
     *
     * @return array<int, array>
     */
    private function sentInterests(User $investor): array
    {
        return $investor->interestsSent()
            ->with('project:id,title')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Interest $interest): array {
                $agreementAvailable = $interest->status === InterestStatus::ACCEPTED
                    && filled($interest->agreement_pdf_path);

                return [
                    'id' => $interest->id,
                    'project' => [
                        'id' => $interest->project_id,
                        'title' => $interest->project?->title,
                    ],
                    'interest_type' => $interest->interest_type?->value,
                    'status' => $interest->status?->value,
                    'sent_at' => $interest->created_at?->toISOString(),
                    'rejection_reason' => $interest->rejection_reason,
                    'can_cancel' => $interest->status === InterestStatus::PENDING,
                    'agreement_available' => $agreementAvailable,
                    'agreement_url' => $agreementAvailable && filled($interest->agreement_id)
                        ? '/api/agreements/'.$interest->agreement_id
                        : null,
                ];
            })
            ->all();
    }

    /**
     * المحفوظات — مع `available` للمشاريع المحذوفة (US-059/6):
     * المشروع soft-deleted يبقى في القائمة بشارة "غير متاح حالياً" وقابل للإزالة.
     *
     * @return array<int, array>
     */
    private function savedProjects(User $investor): array
    {
        return $investor->savedProjects()
            ->with([
                'project' => fn ($q) => $q->withTrashed()->with([
                    'category',
                    'files' => fn ($q) => $q->where('type', 'image'),
                ]),
            ])
            ->orderByDesc('saved_projects.created_at')
            ->get()
            ->map(function (SavedProject $saved): array {
                $project = $saved->project;

                return [
                    'saved_id' => $saved->id,
                    'saved_at' => $saved->created_at?->toISOString(),
                    'project' => [
                        'id' => $project->id,
                        'title' => $project->title,
                        'category' => $project->category?->name(),
                        'status' => $project->status?->value,
                        'ai_score' => ScoreFormatter::format($project->ai_score),
                        'cover_image_url' => $project->coverUrl(),
                        'available' => ! $project->trashed(),
                    ],
                ];
            })
            ->all();
    }
}
