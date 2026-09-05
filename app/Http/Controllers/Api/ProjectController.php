<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectState;
use App\Enums\ProjectStatus;
use App\Events\ProjectContentChanged;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Evaluation;
use App\Models\Project;
use App\Services\Project\ProjectService;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المشاريع — SRS-API-13..17, 19, 20, 21 · L2/L3.
 * L2: index (معرض عام) · show (إفصاح 1/2/3) · L3: CRUD لصاحب الفكرة.
 *
 * T160/T162/T163: الإنشاء/التحديث في ProjectService · التحقق في Form Requests ·
 * التفويض عبر ProjectPolicy (بدل isOwner() المبعثرة).
 */
class ProjectController
{
    use ApiResponse;

    public function __construct(private readonly ProjectService $projects)
    {
    }

    // ——————————————————————— L2: المعرض العام (RL-PUB-01 · 30/دقيقة) ———————————————————————

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(ProjectState::class)],          // T166: status بدل state
            'min_score' => ['nullable', 'numeric', 'between:0,100'],
            'max_score' => ['nullable', 'numeric', 'between:0,100'],
            'sort' => ['nullable', Rule::in(['ai_score', 'created_at', 'view_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.Project::MAX_PAGE_SIZE],
        ]);

        $perPage = min((int) $request->input('per_page', Project::DEFAULT_PAGE_SIZE), Project::MAX_PAGE_SIZE);

        $minScore = $request->has('min_score') ? (float) $request->input('min_score') : null;
        $maxScore = $request->has('max_score') ? (float) $request->input('max_score') : null;

        $projects = Project::query()
            ->with(['category', 'files' => fn ($q) => $q->where('type', 'image')])
            ->published()
            ->ofCategory($request->input('category'))
            ->ofState($request->input('status'))
            ->scoreBetween($minScore, $maxScore)
            ->search($request->input('q'))
            ->sortBy($request->input('sort'), $request->input('direction'))
            ->paginate($perPage);

        return $this->paginated($projects, $projects->map(fn (Project $p) => $p->toCardArray()));
    }

    // ——————————————————————— L2: تفاصيل المشروع (RL-PUB-02 · 60/دقيقة — مخزّن مؤقتاً) ———————————————————————

    public function show(Request $request, Project $project): JsonResponse
    {
        // المسار عام (L2) — حارس sanctum يقرأ التوكن إن وُجد (إفصاح 1/2/3)
        $viewer = $request->user('sanctum');

        // فقط المنشور — إلا للمالك (المسودة/المؤرشف في لوحة التحكم)
        if ($project->publication_status !== ProjectStatus::PUBLISHED && ! $project->isOwner($viewer)) {
            return $this->notFound();
        }

        // عدّ المشاهدات (بلا عدّ لمالكها)
        if ($project->publication_status === ProjectStatus::PUBLISHED && ! $project->isOwner($viewer)) {
            $project->increment('view_count');
        }

        $project->loadMissing(['category', 'files', 'owner']);

        // T161: التفاصيل الكاملة مع الإفصاح حسب الدور — ProjectResource::detail()
        return $this->success(ProjectResource::make($project)->detail($viewer));
    }

    // ——————————————————————— L3: الإنشاء (RL-IO-04 · 10/دقيقة) ———————————————————————

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());
        $project->refresh()->load(['category', 'files', 'owner']);

        return $this->created(ProjectResource::make($project)->detail($request->user()), __('projects.created'));
    }

    // ——————————————————————— L3: التحديث (RL-IO-05 · 10/دقيقة) ———————————————————————

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $result = $this->projects->update($project, $request->validated());
        $result['project']->refresh()->load(['category', 'files', 'owner']);

        // T166: significant_changes بدل needs_reevaluation (contract §PUT · US-022)
        $response = [
            'project' => ProjectResource::make($result['project'])->detail($request->user()),
            'significant_changes' => $result['significant_changes'],
        ];

        // T079: تغييرات جوهرية → prompt يرشد المستخدم (contract §PUT) + حدث ProjectContentChanged
        // (InvalidateEvaluationCache لا يُبطل هنا بموجب SRS-AI-C02 — الحذف عند تأكيد إعادة التقييم فقط).
        if ($result['significant_changes']) {
            $response['prompt'] = 'لقد أجريت تغييرات جوهرية. هل تريد إعادة تقييم مشروعك؟';

            ProjectContentChanged::dispatch($result['project'], $result['changed_significant_fields']);
        }

        return $this->success($response, __('projects.updated'));
    }

    // ——————————————————————— L3: الحذف الناعم (RL-IO-06 · 10/دقيقة — سلة 30 يوماً) ———————————————————————

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if ($request->user()->cannot('destroy', $project)) {
            return $this->forbidden();
        }

        $project->delete();

        return $this->noContent(__('projects.moved_to_trash'));
    }

    /** الاستعادة من سلة المهملات — خلال 30 يوماً (SRS-F02-06) */
    public function restore(Request $request, Project $project): JsonResponse
    {
        return app(TrashController::class)->restore($request, $project);
    }

    // ——————————————————————— L3/L4: آخر 5 تقييمات مكتملة (RL-IO-08 · 30/دقيقة) ———————————————————————

    public function evaluations(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Owner دائماً / Investor بعد اتفاق مقبول — خلاف ذلك 403
        if (! $project->isOwner($user) && ! ($user && $project->hasAcceptedInterestFrom($user))) {
            return $this->forbidden();
        }

        $access = 'full'; // الطرفان المخوّلان يريان كل شيء (مستوى 3)

        $evaluations = $project->evaluations()
            ->whereIn('status', [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL])
            ->limit(5)
            ->get()
            ->map(fn (Evaluation $e) => $e->toReportArray($access));

        return $this->success($evaluations);
    }
}
