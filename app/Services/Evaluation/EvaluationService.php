<?php

namespace App\Services\Evaluation;

use App\Ai\Agents\EvaluationOrchestrator;
use App\Ai\Dtos\EvaluationReport;
use App\Enums\EvaluationStatus;
use App\Enums\ModelUsed;
use App\Events\AllAiProvidersFailed;
use App\Exceptions\Ai\AllProvidersFailedException;
use App\Exceptions\Ai\EvaluationCooldownException;
use App\Exceptions\Ai\EvaluationInProgressException;
use App\Exceptions\Ai\ProviderException;
use App\Models\AiRequestLog;
use App\Models\Evaluation;
use App\Models\Project;
use App\Support\EvaluationStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * دورة حياة التقييم — plan.md §1.1/§2/§4 (US-016/018/019/020 · SRS-AI-C01/C03 · FR-203).
 *
 * المسؤوليات:
 *   - `runEvaluation()`  : يدفع آلة الحالات (pending→processing→terminal) وينفّذ المحرك
 *                          ويحفظ النتيجة ويحدّث المشروع ويطلق الحدث النهائي.
 *   - `atomicallyCreateEvaluation()` : قفل ذري (Redis + lockForUpdate) يمنع التقييمات
 *                          المتزامنة المكررة ويطبق قاعدة 24 ساعة (US-024-S4).
 *   - `assertCooldown()` : مصدر الحقيقة = `projects.last_evaluation_at` (plan.md §4.1).
 *   - `assertNoActiveEvaluation()` : يمنع تقييمين نشطين (pending|processing) لنفس المشروع.
 *   - `cachedEvaluationResponse()` : الاستجابة المخزَّنة (SRS-AI-C03 — 200 بدل 429 في /evaluate).
 *
 * @final
 */
final class EvaluationService
{
    public function __construct(
        private readonly EvaluationEngineFactory $engineFactory,
        private readonly EvaluationCacheService $cacheService,
        private readonly EvaluationInputSnapshotter $snapshotter,
        private readonly EvaluationStateMachine $stateMachine,
    ) {
    }

    /**
     * تشغيل تقييم من Job (Sprint 2 — plan.md §2.1).
     *
     * التدفق: pending|partial|failed → processing → completed|partial|failed.
     * أي استثناء أثناء تنفيذ المحرك يُترجم إلى حالة `failed` مع error_log (SRS-AI-F04)
     * — لا خطأ تقني خام للمستخدم، ويبقى زر إعادة المحاولة متاحاً.
     */
    public function runEvaluation(Evaluation $evaluation): Evaluation
    {
        // حارس مكرر: وِظيفة مكررة لنفس المشروع — عامل آخر يعالجها.
        if ($evaluation->status === EvaluationStatus::PROCESSING) {
            return $evaluation;
        }

        $project = $evaluation->project;

        if (! $project instanceof Project) {
            $this->markFailed($evaluation, new \RuntimeException('Evaluation project not found.'));
            throw new \RuntimeException("Evaluation #{$evaluation->id} has no project.");
        }

        $this->beginProcessing($evaluation, $project);

        try {
            $input = $this->snapshotter->snapshot($project, $evaluation);
            $input['model_used'] = null; // يُحدَّد فعلياً من ai_request_logs (FR-206/207)

            /** @var EvaluationOrchestrator $engine */
            $engine = $this->engineFactory->make();
            $report = $engine->evaluate($input, $this->languageFor($project));

            return $this->complete($evaluation, $project, $report);
        } catch (Throwable $e) {
            return $this->markFailed($evaluation, $e);
        }
    }

    /**
     * إنشاء تقييم جديد (pending) بشكل ذرّي — قفل Redis + قفل صف DB (US-024-S4).
     *
     * @throws EvaluationCooldownException إذا كانت فترة الهدوء 24h نشطة
     * @throws EvaluationInProgressException إذا وُجد تقييم pending|processing نشط
     */
    public function atomicallyCreateEvaluation(Project $project): Evaluation
    {
        $lock = Cache::lock($this->cacheService->lockKey((int) $project->id), 30);

        return $lock->block(5, function () use ($project) {
            /** @var Project|null $fresh */
            $fresh = Project::whereKey($project->id)->lockForUpdate()->first();

            if (! $fresh) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Project not found.');
            }

            $this->assertCooldown($fresh);
            $this->assertNoActiveEvaluation($fresh);

            // §4.3: حذف مفاتيح الكاش فقط بعد التأكيد — لا قبل (FR-216).
            $this->cacheService->forgetProject((int) $fresh->id);

            return $this->createPendingEvaluation($fresh);
        });
    }

    public function createPendingEvaluation(Project $project): Evaluation
    {
        $version = (int) Evaluation::where('project_id', $project->id)->max('version') + 1;

        return Evaluation::create([
            'project_id' => $project->id,
            'version' => $version,
            'status' => EvaluationStatus::PENDING,
        ]);
    }

    /**
     * قاعدة 24 ساعة — مصدر الحقيقة = projects.last_evaluation_at (plan.md §4.1).
     * مدة الهدوء: 24h بعد completed، 1h بعد partial (data-model.md §2.4).
     *
     * @throws EvaluationCooldownException
     */
    public function assertCooldown(Project $project): void
    {
        if ($project->last_evaluation_at === null) {
            return;
        }

        $hours = $this->cooldownHours($project);

        $nextAllowedAt = $project->last_evaluation_at->copy()->addHours($hours);

        if (now()->lt($nextAllowedAt)) {
            throw new EvaluationCooldownException(
                max(1, (int) now()->diffInSeconds($nextAllowedAt)),
                $nextAllowedAt,
            );
        }
    }

    /**
     * منع تقييمين نشطين لنفس المشروع — يشمل pending (بانتظار queue) و processing.
     *
     * @throws EvaluationInProgressException
     */
    public function assertNoActiveEvaluation(Project $project): void
    {
        $active = Evaluation::where('project_id', $project->id)
            ->whereIn('status', [EvaluationStatus::PENDING, EvaluationStatus::PROCESSING])
            ->exists();

        if ($active) {
            throw new EvaluationInProgressException((int) $project->id);
        }
    }

    /**
     * الاستجابة المخزَّنة عند فترة الهدوء (SRS-AI-C03 — /evaluate تُرجع 200، /re-evaluate تُرجع 429).
     *
     * @param  bool  $includePartial  false في /retry فقط (SRS-AI-E05): كاش 24h يمنع إعادة
     *                                المحاولة حصراً عند وجود تقييم `completed` مخزَّن — بينما
     *                                الـ partial يخضع لمهلة 1h ثم يُعاد (data-model.md §2.4).
     *
     * @return array<string, mixed>|null null إذا لم تكن فترة الهدوء نشطة أو لا يوجد تقييم سابق
     */
    public function cachedEvaluationResponse(Project $project, bool $includePartial = true): ?array
    {
        if ($project->last_evaluation_at === null) {
            return null;
        }

        $statuses = [EvaluationStatus::COMPLETED];

        if ($includePartial) {
            $statuses[] = EvaluationStatus::PARTIAL;
        }

        $latest = $project->evaluationHistory()
            ->whereIn('status', $statuses)
            ->latest('completed_at')
            ->first();

        if ($latest === null || $latest->completed_at === null) {
            return null;
        }

        $hours = $this->cooldownHoursFor($latest);
        $nextAllowedAt = $latest->completed_at->copy()->addHours($hours);

        if (! now()->lt($nextAllowedAt)) {
            return null;
        }

        $remainingMinutes = max(1, (int) ceil(now()->diffInMinutes($nextAllowedAt)));
        $hours = intdiv($remainingMinutes, 60);
        $minutes = $remainingMinutes % 60;

        return [
            'evaluation_id' => $latest->id,
            'overall_score' => $latest->overall_score,
            'status' => $latest->status->value,
            'last_evaluation_at' => $latest->completed_at->toISOString(),
            'next_evaluation_at' => $nextAllowedAt->toISOString(),
            'remaining_seconds' => max(1, (int) now()->diffInSeconds($nextAllowedAt)),
            'message' => sprintf(
                'آخر تقييم: %s — التقييم التالي بعد %d ساعة %d دقيقة',
                $latest->completed_at->format('Y-m-d'),
                $hours,
                $minutes,
            ),
        ];
    }

    /**
     * معلومات الهدوء لعرض المؤقّت (قائمة السجل / الحالة).
     *
     * @return array{next_evaluation_at: string, remaining_seconds: int}|null
     */
    public function cooldownInfo(Project $project): ?array
    {
        if ($project->last_evaluation_at === null) {
            return null;
        }

        $next = $project->last_evaluation_at->copy()->addHours($this->cooldownHours($project));

        return [
            'next_evaluation_at' => $next->toISOString(),
            'remaining_seconds' => now()->lt($next)
                ? max(1, (int) now()->diffInSeconds($next))
                : 0,
        ];
    }

    // ——————————————————————— التنفيذ الداخلي ———————————————————————

    /**
     * pending|failed|partial → processing مع فرض الحراس (data-model.md §2.4).
     */
    private function beginProcessing(Evaluation $evaluation, Project $project): void
    {
        $hasActiveProcessing = Evaluation::where('project_id', $project->id)
            ->where('status', EvaluationStatus::PROCESSING)
            ->where('id', '!=', $evaluation->id)
            ->exists();

        $this->stateMachine->transition($evaluation->status, EvaluationStatus::PROCESSING, [
            'now' => now(),
            'last_evaluation_at' => $project->last_evaluation_at,
            'has_active_processing' => $hasActiveProcessing,
            'project_id' => (int) $project->id,
            're_evaluation_hours' => (int) config('ai.re_evaluation_cache_hours', 24),
            'partial_retry_hours' => (int) config('ai.partial_retry_hours', 1),
        ]);

        $evaluation->fill([
            'status' => EvaluationStatus::PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'processing_time_ms' => null,
            'error_log' => null,
            'result' => null,
            'overall_score' => null,
            'confidence_score' => null,
        ])->save();
    }

    /**
     * حفظ التقرير النهائي وتحديث المشروع وإطلاق الحدث (plan.md §1.6 / §5.3).
     */
    private function complete(Evaluation $evaluation, Project $project, EvaluationReport $report): Evaluation
    {
        $result = $report->toArray();
        $terminal = $report->partialDimensions !== []
            ? EvaluationStatus::PARTIAL
            : EvaluationStatus::COMPLETED;

        $completedAt = now();

        $providerInfo = $this->providerInfo($evaluation);
        $modelUsed = $providerInfo['provider']
            ?? ($report->modelUsed !== null ? ModelUsed::tryFrom($report->modelUsed) : null);

        DB::transaction(function () use ($evaluation, $project, $result, $terminal, $completedAt, $report, $providerInfo, $modelUsed) {
            $evaluation->fill([
                'status' => $terminal,
                'overall_score' => round($report->overallScore, 1),
                'confidence_score' => $report->confidenceScore,
                'result' => $result,
                'model_used' => $modelUsed,
                'model_name' => $providerInfo['model_name'],
                'provider_used' => ($modelUsed?->value) ?? $report->modelUsed,
                'consensus_rounds' => (int) ($result['consensus_rounds'] ?? 0),
                'processing_time_ms' => $evaluation->started_at !== null
                    ? (int) $evaluation->started_at->diffInMilliseconds($completedAt)
                    : null,
                'completed_at' => $completedAt,
                'error_log' => null,
            ])->save();

            // saveQuietly: لا إعادة إرسال أحداث (منع الحلقات) — مزامنة الفهرس عبر المستمع (plan §5.3).
            $project->forceFill([
                'ai_score' => round($report->overallScore, 1),
                'last_evaluation_at' => $completedAt,
            ])->saveQuietly();
        });

        // كاش §4.2: نتيجة + مؤقّت الهدوء.
        $this->cacheService->storeResult((int) $evaluation->id, $result);

        $cooldown = $this->cooldownInfo($project) ?? [
            'next_allowed_at' => $completedAt->copy()->addHours($this->cooldownHoursFor($evaluation))->toISOString(),
            'remaining_seconds' => 1,
        ];

        $this->cacheService->storeCooldown(
            (int) $project->id,
            $cooldown,
            max(1, (int) ($cooldown['remaining_seconds'] ?? 1)),
        );

        // T050: الحدث النهائي يطلقه EvaluationObserver عند `saved` بالحالة النهائية
        // (حارس wasChanged('status')) — لا تصريح مباشر هنا (منع الإطلاق المزدوج).

        return $evaluation;
    }

    /**
     * فشل → error_log + حالة failed + حدث — بلا cooldown وبلا تحديث last_evaluation_at
     * (US-019-S4: زر إعادة المحاولة متاح فوراً) (plan.md §4.3).
     */
    private function markFailed(Evaluation $evaluation, Throwable $e): Evaluation
    {
        $errorLog = is_array($evaluation->error_log) ? $evaluation->error_log : [];
        $errorLog[] = [
            'type' => $e instanceof AllProvidersFailedException ? 'all_providers_failed' : 'evaluation_failed',
            'provider' => $e instanceof ProviderException ? $e->provider() : null,
            'attempt' => $e instanceof ProviderException ? $e->attempt() : null,
            'reason' => $e instanceof ProviderException ? $e->reason() : $this->classBasename($e),
            'message' => $e->getMessage(),
            'timestamp' => now()->toISOString(),
        ];

        $evaluation->fill([
            'status' => EvaluationStatus::FAILED,
            'error_log' => $errorLog,
            'completed_at' => now(),
            'processing_time_ms' => $evaluation->started_at !== null
                ? (int) $evaluation->started_at->diffInMilliseconds(now())
                : null,
        ])->save();

        // فشل ← لا cooldown — أزل أي مؤقّت قديم (SRS-AI-E05).
        $this->cacheService->forgetProject((int) $evaluation->project_id);

        // T050: حدث الفشل يطلقه EvaluationObserver عند `saved` (حارس wasChanged('status')) — لا تصريح مزدوج.

        // T059: فشل جميع المزوّدين معاً → تنبيه داخلي للمشرفين (FR-222) —
        // بلا كشف الخطأ التقني الخام للمستخدم (SRS-AI-F04 · المبدأ V).
        if ($e instanceof AllProvidersFailedException) {
            AllAiProvidersFailed::dispatch($evaluation, $e->failures());
        }

        return $evaluation;
    }

    /**
     * المزوّد والنموذج اللذان نجحا فعلياً — من ai_request_logs (FR-206/207 / plan.md §3.4).
     *
     * @return array{provider: ModelUsed|null, model_name: string|null}
     */
    private function providerInfo(Evaluation $evaluation): array
    {
        $last = AiRequestLog::where('evaluation_id', $evaluation->id)
            ->where('success', true)
            ->latest('id')
            ->first();

        return [
            'provider' => $last?->provider,
            'model_name' => $last?->model,
        ];
    }

    /**
     * لغة محتوى المشروع — عربية افتراضياً (المبدأ III)، إنجليزية عند غلبة الحروف اللاتينية.
     */
    private function languageFor(Project $project): string
    {
        $description = (string) $project->description;

        if ($description === '') {
            return 'ar';
        }

        $latin = preg_match_all('/[A-Za-z]/', $description, $matches) ? count($matches[0]) : 0;

        return ($latin / max(1, mb_strlen($description))) > 0.6 ? 'en' : 'ar';
    }

    /**
     * مدة الهدوء (ساعات) حسب آخر تقييم نهائي للمشروع.
     */
    private function cooldownHours(Project $project): int
    {
        $latest = $project->evaluationHistory()
            ->whereIn('status', [EvaluationStatus::COMPLETED, EvaluationStatus::PARTIAL])
            ->latest('completed_at')
            ->first();

        return $this->cooldownHoursFor($latest);
    }

    private function cooldownHoursFor(?Evaluation $evaluation): int
    {
        return $evaluation?->status === EvaluationStatus::PARTIAL
            ? (int) config('ai.partial_retry_hours', 1)
            : (int) config('ai.re_evaluation_cache_hours', 24);
    }

    private function classBasename(Throwable $e): string
    {
        $path = explode('\\', $e::class);

        return (string) end($path);
    }
}
