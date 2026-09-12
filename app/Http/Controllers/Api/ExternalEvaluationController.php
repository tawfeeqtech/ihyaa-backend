<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
use App\Http\Requests\ExternalEvaluationRequest;
use App\Models\Evaluation;
use App\Models\Project;
use App\Services\Evaluation\EvaluationService;
use App\Services\External\PythonEvaluationClient;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalEvaluationController
{
    use ApiResponse;

    public function __construct(
        private readonly EvaluationService $evaluationService,
        private readonly PythonEvaluationClient $python,
    ) {
    }

    public function store(ExternalEvaluationRequest $request): JsonResponse
    {
        $project = Project::findOrFail((int) $request->validated('project_id'));

        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        if ($project->publication_status !== ProjectStatus::PUBLISHED) {
            return $this->unprocessable(
                'UNEVALUABLE_PROJECT',
                'The project must be published before external evaluation.',
                ['publication_status' => ['Expected published.']],
            );
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return $this->unprocessable(
                'IDEMPOTENCY_KEY_REQUIRED',
                'Idempotency-Key header is required and must not exceed 128 characters.',
            );
        }

        $existing = Evaluation::where('external_idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            if ((int) $existing->project_id !== (int) $project->id) {
                return $this->conflict(
                    'IDEMPOTENCY_KEY_CONFLICT',
                    'The Idempotency-Key is already associated with another project.',
                );
            }

            return $this->success($this->evaluationPayload($existing), 'already accepted', 200, ['cached' => true]);
        }

        $project->loadMissing('category');
        $projectPayload = $this->projectPayload($project);
        $evaluation = $this->evaluationService->startExternalEvaluation($project, $projectPayload, $idempotencyKey);

        try {
            $pythonResponse = $this->python->start($project, $evaluation, $projectPayload, $idempotencyKey);
        } catch (Throwable $exception) {
            Log::error('external_evaluation.python_dispatch_failed', [
                'evaluation_id' => $evaluation->id,
                'project_id' => $project->id,
                'python_url' => config('services.python_evaluation.url'),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->evaluationService->markExternalDispatchFailed($evaluation, $exception);

            return $this->error('PYTHON_EVALUATION_UNAVAILABLE', 'The evaluation service is unavailable.', 502);
        }

        $evaluation->fill([
            'python_evaluation_id' => (string) $pythonResponse['evaluation_id'],
            'status' => EvaluationStatus::PROCESSING,
        ])->save();

        return $this->created($this->evaluationPayload($evaluation), 'evaluation submitted');
    }

    public function status(Request $request, Evaluation $evaluation): JsonResponse
    {
        if (! $this->canView($request, $evaluation)) {
            return $this->notFound();
        }

        return $this->success([
            'evaluation_id' => $evaluation->id,
            'project_id' => $evaluation->project_id,
            'python_evaluation_id' => $evaluation->python_evaluation_id,
            'status' => $evaluation->status->value,
            'overall_score' => $evaluation->overall_score,
            'confidence_score' => $evaluation->confidence_score,
            'started_at' => $evaluation->started_at?->toISOString(),
            'completed_at' => $evaluation->completed_at?->toISOString(),
        ]);
    }

    public function show(Request $request, Evaluation $evaluation): JsonResponse
    {
        if (! $this->canView($request, $evaluation)
            || ! in_array($evaluation->status, [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL], true)
            || ! is_array($evaluation->result)) {
            return $this->notFound();
        }

        return $this->success(array_merge([
            'evaluation_id' => $evaluation->id,
            'project_id' => $evaluation->project_id,
            'status' => $evaluation->status->value,
        ], $evaluation->result));
    }

    /** @return array<string, mixed> */
    private function projectPayload(Project $project): array
    {
        return [
            'title' => $project->title,
            'description' => $project->description,
            'bio' => $project->bio,
            'category_id' => $project->category_id,
            'status' => $project->status?->value,
            'publication_status' => $project->publication_status?->value,
            'tags' => $project->tags ?? [],
            'team' => $project->team ?? [],
            'github_url' => $project->github_url,
            'video_url' => $project->video_url,
            'video_provider' => $project->video_provider?->value,
            'budget_min' => $project->budget_min,
            'budget_max' => $project->budget_max,
            'visibility_level' => $project->visibility_level?->value,
        ];
    }

    private function canView(Request $request, Evaluation $evaluation): bool
    {
        return $evaluation->external_idempotency_key !== null
            && $evaluation->project?->isOwner($request->user());
    }

    /** @return array<string, mixed> */
    private function evaluationPayload(Evaluation $evaluation): array
    {
        return [
            'evaluation_id' => $evaluation->id,
            'project_id' => $evaluation->project_id,
            'python_evaluation_id' => $evaluation->python_evaluation_id,
            'status' => $evaluation->status->value,
            'version' => $evaluation->version,
        ];
    }
}
