<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المشاريع المحفوظة — SRS-API-33/34 · SRS-F11-04 (Investor فقط).
 * حفظ/إزالة بنقرة واحدة — حفظ واحد فقط لكل مستثمر/مشروع (فهرس فريد).
 */
class SavedProjectController
{
    use ApiResponse;

    /** RL-INV-07 · 10/دقيقة */
    public function save(Request $request, Project $project): JsonResponse
    {
        if ($project->publication_status !== ProjectStatus::PUBLISHED) {
            return $this->unprocessable('PROJECT_NOT_PUBLISHED', __('saved.not_published'));
        }

        $request->user()->savedProjects()->firstOrCreate(['project_id' => $project->id]);

        return $this->created(['saved' => true], __('saved.saved'));
    }

    /** RL-INV-08 · 10/دقيقة */
    public function unsave(Request $request, Project $project): JsonResponse
    {
        $request->user()->savedProjects()->where('project_id', $project->id)->delete();

        return $this->success(['saved' => false], __('saved.removed'));
    }

    /** RL-INV-06 · 60/دقيقة */
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->savedProjects()
            ->with(['project.category', 'project.files' => fn ($q) => $q->where('type', 'image')])
            ->orderByDesc('saved_projects.created_at')
            ->paginate(Project::DEFAULT_PAGE_SIZE);

        return $this->paginated(
            $projects,
            $projects->map(fn ($saved) => $saved->project->toCardArray())
        );
    }
}
