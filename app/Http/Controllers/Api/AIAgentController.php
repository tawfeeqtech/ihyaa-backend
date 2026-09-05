<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArtifactStatus;
use App\Http\Requests\AnalyzeProjectRequest;
use App\Http\Resources\AiAgentArtifactResource;
use App\Jobs\AnalyzeProjectJob;
use App\Models\AiAgentArtifact;
use App\Models\Project;
use App\Services\AI\AgentReportPdfExporter;
use App\Services\AI\ArtifactVersioner;
use App\Support\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * وكيل AI: تحليل المشاريع — SRS-API-42/43 · RL-AI-01/02 · EPIC-15 (US-080..084).
 *
 * أنواع: comparison | swot | competitive (نص/قوالب فقط في MVP — لا MCP خارجي).
 * المسار غير المتزامن: Cache::lock يمنع التحديث المتزامن (409) ← إنشاء artifact
 * بحالة processing ← AnalyzeProjectJob على Horizon ← status completed|failed
 * + إشعار analysis_completed. القراءة/التصدير: المالك فقط (Policy — 403).
 */
class AIAgentController
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly ArtifactVersioner $versioner,
        private readonly AgentReportPdfExporter $pdfExporter,
    ) {
    }

    /**
     * RL-AI-01 · 3/دقيقة لكل (مستخدم + مشروع) — T103.
     * 202 (processing, version) · 403 غير المالك · 422 مشروع غير مقيَّم · 409 قيد المعالجة.
     */
    public function analyze(AnalyzeProjectRequest $request, Project $project): JsonResponse
    {
        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        if (! $project->latestCompletedEvaluation()) {
            return $this->unprocessable('PROJECT_NOT_EVALUATED', __('ai_agent.project_not_evaluated'));
        }

        $type = $request->validated()['analysis_type'];
        $language = $request->validated()['language'] ?? 'ar';

        $lock = Cache::lock("ai-analysis:{$project->id}:{$type}", 600);

        if (! $lock->get()) {
            return $this->conflict('ANALYSIS_IN_PROGRESS', __('ai_agent.analysis_in_progress'));
        }

        try {
            $artifact = $this->versioner->createProcessing((int) $project->id, $type, $language);

            // lockOwner يُمَرَّر إلى الـ Job فيحرّر قفل الـ Controller في finally (T104)
            AnalyzeProjectJob::dispatch($artifact->id, $lock->owner());
        } catch (\Throwable $e) {
            $lock->release();

            throw $e;
        }

        return $this->accepted([
            'artifact_id' => $artifact->id,
            'project_id' => (int) $project->id,
            'analysis_type' => $type,
            'version' => $artifact->version,
            'status' => ArtifactStatus::PROCESSING->value,
            'message' => __('ai_agent.analysis_queued'),
        ], __('ai_agent.analysis_queued'));
    }

    /** RL-AI-02 · 10/دقيقة — T115 · T116: قراءة نتيجة محددة (المالك فقط) */
    public function show(Request $request, AiAgentArtifact $artifact): JsonResponse
    {
        $this->authorize('view', $artifact);

        return $this->success(AiAgentArtifactResource::make($artifact));
    }

    /** T115: أحدث إصدار لكل نوع تحليل لمشروع (المالك فقط) — ?type= فلترة اختيارية */
    public function projectAnalysis(Request $request, Project $project): JsonResponse
    {
        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        $request->validate([
            'type' => ['nullable', Rule::in(['comparison', 'swot', 'competitive'])],
        ]);

        $type = $request->query('type');

        $artifacts = AiAgentArtifact::query()
            ->where('project_id', $project->id)
            ->when($type, fn ($q) => $q->where('analysis_type', $type))
            ->get()
            ->groupBy('analysis_type')
            ->map(fn ($group) => $group->sortByDesc('version')->first())
            ->values();

        return $this->success(AiAgentArtifactResource::collection($artifacts));
    }

    /** T118: تصدير PDF (المالك فقط) — 409 ANALYSIS_INCOMPLETE إن لم يكتمل */
    public function export(Request $request, AiAgentArtifact $artifact): JsonResponse|Response
    {
        $this->authorize('view', $artifact);

        if ($artifact->status !== ArtifactStatus::COMPLETED) {
            return $this->conflict('ANALYSIS_INCOMPLETE', __('ai_agent.analysis_incomplete'));
        }

        $pdf = $this->pdfExporter->export($artifact);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="analysis-'.$artifact->id.'.pdf"',
        ]);
    }
}
