<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\Ai\EvaluationCooldownException;
use App\Exceptions\Ai\EvaluationInProgressException;
use App\Jobs\EvaluateProjectJob;
use App\Jobs\RetryEvaluationJob;
use App\Models\AiRequestLog;
use App\Models\Evaluation;
use App\Models\Project;
use App\Services\Evaluation\EvaluationService;
use App\Support\Traits\ApiResponse;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * التقييم وإعادة التقييم — contracts/evaluation-api.md (SRS-API-44..47 · SRS-API-19).
 *
 * نقطة البداية الوحيدة لتقييم مشروع (US-016): تُنشئ سجل pending ذرياً
 * (قفل Redis + lockForUpdate) ثم تُصدر Job غير متزامن — لا عمل ثقيل في الطلب.
 *
 * | النقطة | الحالة |
 * |---|---|
 * | POST /projects/{project}/evaluate | 201 pending · 200 cached (كاش 24h) · 409 جارٍ المعالجة · 422 غير قابل |
 * | POST /projects/{project}/re-evaluate | 201 manual · 429 COOLDOWN_ACTIVE · 422 CONFIRMATION_REQUIRED |
 * | POST /projects/{project}/evaluations/{evaluation}/retry | 202 · 422 NOT_FAILED · 200 cached (SRS-AI-E05) |
 * | GET /projects/{project}/evaluation-status | 200 حالة فورية (US-016-S5) |
 * | GET /projects/{project}/evaluations | 200 آخر 5 مكتملة (US-018/023) |
 */
class EvaluationController
{
    use ApiResponse;

    public function __construct(
        private readonly EvaluationService $evaluationService,
    ) {
    }

    /** POST /api/projects/{project}/evaluate — بدء تقييم AI (SRS-API-44) */
    public function evaluate(Request $request, Project $project): JsonResponse
    {
        return $this->startEvaluation($request, $project, 'auto');
    }

    /** POST /api/projects/{project}/re-evaluate — إعادة تقييم يدوية (SRS-API-45 · US-021) */
    public function reEvaluate(Request $request, Project $project): JsonResponse
    {
        if (! $request->boolean('confirm')) {
            return $this->unprocessable(
                'CONFIRMATION_REQUIRED',
                'يجب تأكيد بدء إعادة التقييم',
                ['confirm' => ['مطلوب true']],
            );
        }

        return $this->startEvaluation($request, $project, 'manual');
    }

    /** POST /api/projects/{project}/evaluations/{evaluation}/retry — إعادة محاولة فاشل/جزئي (SRS-API-46) */
    public function retry(Request $request, Project $project, Evaluation $evaluation): JsonResponse
    {
        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        if ((int) $evaluation->project_id !== (int) $project->id) {
            return $this->error('EVALUATION_NOT_FOUND', 'التقييم غير موجود', 404);
        }

        if ($evaluation->status !== EvaluationStatus::FAILED
            && $evaluation->status !== EvaluationStatus::PARTIAL) {
            return $this->unprocessable('NOT_FAILED', __('evaluation.not_failed'));
        }

        // SRS-AI-E05: كاش 24h يمنع — إن وُجد تقييم completed مخزَّن أُعيد المخزَّن بدل المحاولة.
        $cached = $this->evaluationService->cachedEvaluationResponse($project, includePartial: false);

        if ($cached !== null) {
            return $this->success($cached, 'cached', 200, ['cached' => true]);
        }

        // الـ partial يخضع لمهلة 1h قبل إعادة المحاولة (data-model.md §2.4).
        try {
            $this->evaluationService->assertCooldown($project);
        } catch (EvaluationCooldownException $e) {
            return $this->cooldownResponse($project, $e);
        }

        $retryCount = (int) $evaluation->retry_count + 1;
        $evaluation->forceFill(['retry_count' => $retryCount])->save();

        RetryEvaluationJob::dispatch($evaluation->id);

        return $this->accepted([
            'id' => $evaluation->id,
            'project_id' => $project->id,
            'status' => 'processing',
            'retry_count' => $retryCount,
            'message' => 'بدأت إعادة المحاولة',
        ]);
    }

    /** GET /api/projects/{project}/evaluation-status — حالة التقييم (SRS-API-47 · US-016-S5) */
    public function status(Request $request, Project $project): JsonResponse
    {
        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        $latest = $project->evaluationHistory()->latest('id')->first();

        if (! $latest) {
            return $this->success([
                'project_id' => $project->id,
                'status' => 'never_evaluated',
            ]);
        }

        $total = count((array) config('ai.weights'));

        $data = [
            'status' => $latest->status->value,
            'latest_evaluation_id' => $latest->id,
            'version' => $latest->version,
            'started_at' => $this->iso($latest->started_at),
            'completed_at' => $this->iso($latest->completed_at),
            'progress' => [
                'completed_dimensions' => $this->completedDimensions($latest),
                'total_dimensions' => $total,
            ],
            'overall_score' => $latest->overall_score,
            'model_used' => $latest->model_used?->value,
            'processing_time_ms' => $latest->processing_time_ms,
            'elapsed_seconds' => $latest->started_at !== null
                ? max(0, (int) $latest->started_at->diffInSeconds(now()))
                : null,
            'ceiling_seconds' => (int) config('ai.ceiling_seconds', 180),
            'can_retry' => $latest->status === EvaluationStatus::FAILED,
            'next_evaluation_at' => $this->evaluationService->cooldownInfo($project)['next_evaluation_at'] ?? null,
            'retry_url' => $latest->status === EvaluationStatus::FAILED
                ? '/api/projects/'.$project->id.'/evaluations/'.$latest->id.'/retry'
                : null,
        ];

        if ($latest->status === EvaluationStatus::COMPLETED || $latest->status === EvaluationStatus::PARTIAL) {
            $data['notification'] = [
                'type' => $latest->status === EvaluationStatus::PARTIAL ? 'evaluation_partial' : 'evaluation_completed',
                'sent_at' => $this->iso($latest->completed_at),
            ];
        }

        if ($latest->status === EvaluationStatus::FAILED) {
            $data['error_summary'] = $this->errorSummary($latest);
        }

        return $this->success($data);
    }

    /** GET /api/projects/{project}/evaluations — سجل آخر 5 تقييمات مكتملة (SRS-API-19 · US-018/023) */
    public function history(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $access = $project->reportAccessFor($user);

        if ($access === 'none') {
            return $this->forbidden();
        }

        $evaluations = $project->evaluationHistory()
            ->where('status', EvaluationStatus::COMPLETED)
            ->latest('completed_at')
            ->limit(5)
            ->get();

        $showDimensions = in_array($access, ['dimensions', 'full'], true);

        $data = [
            'evaluations' => $evaluations->map(
                fn (Evaluation $evaluation) => $this->historyItem($evaluation, $showDimensions)
            )->values()->all(),
            'meta' => [
                'shown_count' => $evaluations->count(),
                'total_completed' => $project->evaluationHistory()
                    ->where('status', EvaluationStatus::COMPLETED)->count(),
                'latest_version' => (int) $project->evaluationHistory()->max('version'),
                'cooldown' => $this->evaluationService->cooldownInfo($project),
            ],
        ];

        if ($project->isOwner($user)) {
            $data['meta']['failed_count'] = $project->evaluationHistory()
                ->where('status', EvaluationStatus::FAILED)->count();
        }

        // US-023: مصفوفة المقارنة عبر الإصدارات (تُغذّي EvaluationComparisonChart) — للمالك فقط.
        if ($project->isOwner($user) && $request->query('include') === 'comparison') {
            $data['comparison'] = $evaluations->map(function (Evaluation $evaluation) {
                $dimensions = is_array($evaluation->result) ? ($evaluation->result['dimensions'] ?? []) : [];

                return [
                    'version' => $evaluation->version,
                    'completed_at' => $this->iso($evaluation->completed_at),
                    'dimensions' => array_map(
                        static fn (array $dimension) => $dimension['score'] ?? null,
                        $dimensions,
                    ),
                ];
            })->values()->all();
        }

        return $this->success($data);
    }

    // ——————————————————————— أدوات ———————————————————————

    /** يُنشئ تقييم pending ذرياً ويُصدر Job — مشترك بين evaluate و re-evaluate. */
    private function startEvaluation(Request $request, Project $project, string $trigger): JsonResponse
    {
        if (! $project->isOwner($request->user())) {
            return $this->forbidden();
        }

        $unevaluable = $this->unevaluableFields($project);

        if ($unevaluable !== null) {
            return $this->unprocessable(
                'UNEVALUABLE_PROJECT',
                'المشروع غير مكتمل البيانات أو خارج نطاق التقييم',
                $unevaluable,
            );
        }

        try {
            $evaluation = $this->evaluationService->atomicallyCreateEvaluation($project);
        } catch (EvaluationCooldownException $e) {
            // /evaluate خلال فترة الهدوء ← 200 cached (SRS-AI-C03)؛ /re-evaluate ← 429.
            if ($trigger === 'auto') {
                $cached = $this->evaluationService->cachedEvaluationResponse($project);

                if ($cached !== null) {
                    return $this->success($cached, 'cached', 200, ['cached' => true]);
                }
            }

            return $this->cooldownResponse($project, $e);
        } catch (EvaluationInProgressException) {
            return $this->error(
                'EVALUATION_IN_PROGRESS',
                'يوجد تقييم قيد المعالجة لهذا المشروع حالياً',
                409,
                [],
                ['active_evaluation_id' => $this->activeEvaluationId($project)],
            );
        }

        EvaluateProjectJob::dispatch($evaluation->id);

        return $this->created([
            'id' => $evaluation->id,
            'project_id' => $project->id,
            'version' => $evaluation->version,
            'status' => $evaluation->status->value,
            'trigger' => $trigger,
            'message' => __('evaluation.queued'),
            'queued_at' => $this->iso($evaluation->created_at),
        ], __('evaluation.queued'));
    }

    /** 429 COOLDOWN_ACTIVE — مع Retry-After و next_evaluation_at (قاعدة 24 ساعة). */
    private function cooldownResponse(Project $project, EvaluationCooldownException $e): JsonResponse
    {
        return $this->error(
            'COOLDOWN_ACTIVE',
            'التقييم التالي متاح بعد '.$this->humanCooldown($e->remainingSeconds()),
            429,
            [],
            [
                'last_evaluation_at' => $this->iso($project->last_evaluation_at),
                'next_evaluation_at' => $this->iso($e->nextAllowedAt()),
                'retry_after_seconds' => $e->remainingSeconds(),
            ],
        )->withHeaders(['Retry-After' => (string) $e->remainingSeconds()]);
    }

    /** تحقق قابلية المشروع للتقييم (FR-223) — draft أو وصف خارج الحدود ← 422. */
    private function unevaluableFields(Project $project): ?array
    {
        $fields = [];

        if ($project->publication_status === ProjectStatus::DRAFT) {
            $fields['publication_status'] = ['لا يمكن تقييم مشروع بحالة مسودة — انشره أولاً'];
        }

        $length = mb_strlen((string) $project->description);

        if ($length < 50 || $length > 2000) {
            $fields['description'] = ['الوصف إلزامي (50-2000 حرف)'];
        }

        return $fields === [] ? null : $fields;
    }

    private function activeEvaluationId(Project $project): ?int
    {
        return Evaluation::where('project_id', $project->id)
            ->whereIn('status', [EvaluationStatus::PENDING, EvaluationStatus::PROCESSING])
            ->orderByDesc('id')
            ->value('id');
    }

    /** عدد الأبعاد المكتملة فعلياً (progress) — completed=5 · جزئي=حجم result · أثناء المعالجة=سجلات النجاح. */
    private function completedDimensions(Evaluation $evaluation): int
    {
        $total = count((array) config('ai.weights'));

        if ($evaluation->status === EvaluationStatus::COMPLETED) {
            return $total;
        }

        if (is_array($evaluation->result) && isset($evaluation->result['dimensions'])) {
            return count($evaluation->result['dimensions']);
        }

        if ($evaluation->status === EvaluationStatus::PENDING || $evaluation->status === EvaluationStatus::PROCESSING) {
            return AiRequestLog::where('evaluation_id', $evaluation->id)
                ->where('success', true)
                ->distinct()
                ->count('dimension');
        }

        return 0;
    }

    /** ملخص فشل مقروء — من آخر سطر في error_log. */
    private function errorSummary(Evaluation $evaluation): string
    {
        $log = is_array($evaluation->error_log) ? $evaluation->error_log : [];
        $last = $log === [] ? null : end($log);
        $type = is_array($last) ? ($last['type'] ?? null) : null;

        return match ($type) {
            'all_providers_failed' => 'فشل الاتصال بجميع مزودي الذكاء الاصطناعي',
            default => 'فشل تقييم المشروع — يمكنك إعادة المحاولة',
        };
    }

    /** عنصر سجل تقييم — الأبعاد تُحذف لغير المالك/L1 (مصفوفة الإفصاح US-029). */
    private function historyItem(Evaluation $evaluation, bool $showDimensions): array
    {
        $item = [
            'id' => $evaluation->id,
            'version' => $evaluation->version,
            'status' => $evaluation->status->value,
            'overall_score' => $evaluation->overall_score,
            'confidence_score' => $evaluation->confidence_score,
            'model_used' => $evaluation->model_used?->value,
            'completed_at' => $this->iso($evaluation->completed_at),
        ];

        if ($showDimensions && is_array($evaluation->result)) {
            $item['dimensions'] = array_map(
                static fn (array $dimension) => $dimension['score'] ?? null,
                $evaluation->result['dimensions'] ?? [],
            );
        }

        return $item;
    }

    /** "بعد 23 ساعة و 3 دقائق" — من ثواني الهدوء المتبقية. */
    private function humanCooldown(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d ساعة و %d دقيقة', $hours, $minutes);
    }

    private function iso(null|DateTimeInterface|Carbon $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->toISOString() : null;
    }
}
