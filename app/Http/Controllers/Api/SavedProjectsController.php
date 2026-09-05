<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\SavedProject;
use App\Services\Saved\SavedProjectService;
use App\Support\ScoreFormatter;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المشاريع المحفوظة — US-059 · SRS-API-32/33/34 · saved-projects-api.md (T093).
 * Investor فقط (middleware) · Idempotent (US-059/4):
 *   GET    /saved-projects          → قائمة (المحذوفة soft تظهر available:false)
 *   POST   /projects/{project}/save → 201 جديد / 200 مكرر
 *   DELETE /projects/{project}/save → 200 removed:true/false (بلا حوار تأكيد)
 */
class SavedProjectsController
{
    use ApiResponse;

    public function __construct(private readonly SavedProjectService $service)
    {
    }

    /** RL-INV-06 · 60/دقيقة — contract §1 */
    public function index(Request $request): JsonResponse
    {
        $saved = $request->user()->savedProjects()
            ->with([
                'project' => fn ($q) => $q->withTrashed()->with([
                    'category',
                    'files' => fn ($q) => $q->where('type', 'image'),
                ]),
            ])
            ->orderByDesc('saved_projects.created_at')
            ->paginate(Project::DEFAULT_PAGE_SIZE);

        $data = $saved->map(fn (SavedProject $entry): array => [
            'id' => $entry->id,
            'saved_at' => $entry->created_at?->toISOString(),
            'project' => [
                'id' => $entry->project->id,
                'title' => $entry->project->title,
                'category' => $entry->project->category?->name(),
                'status' => $entry->project->status?->value,
                'ai_score' => ScoreFormatter::format($entry->project->ai_score),
                'budget_min' => ScoreFormatter::format($entry->project->budget_min),
                'budget_max' => ScoreFormatter::format($entry->project->budget_max),
                'cover_image_url' => $entry->project->coverUrl(),
                'available' => ! $entry->project->trashed(),
            ],
        ]);

        return $this->paginated($saved, $data->values());
    }

    /** RL-INV-07 · 30/دقيقة — contract §2 (201 جديد · 200 مكرر) */
    public function store(Request $request, Project $project): JsonResponse
    {
        $result = $this->service->save($request->user(), $project);

        return $this->success(
            $result,
            __('saved.saved'),
            $result['already_saved'] ? 200 : 201,
        );
    }

    /** RL-INV-08 · 30/دقيقة — contract §3 (إزالة بلا حوار · idempotent) */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        return $this->success(
            $this->service->remove($request->user(), $project),
            __('saved.removed'),
        );
    }
}
