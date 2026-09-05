<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تقييم جديد — plan.md §2.1 (US-016 · FR-203).
 *
 * القناة `ai-evaluation` · tries=1 (منطق إعادة المحاولة داخل FallbackManager — SRS-AI-F03)
 * · timeout=200s (> السقف 180s + هامش) · ShouldBeUnique بمفتاح project_id يمنع تكديس
 * تقييمات مكررة لنفس المشروع خلال 30 ثانية.
 */
class EvaluateProjectJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 200;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 30;

    public function __construct(
        public int $evaluationId,
    ) {
        // القناة عبر onQueue() — لا إعادة تعريف خاصية $queue (Queueable يملكها بلا نوع).
        $this->onQueue('ai-evaluation');
    }

    /**
     * المفتاح الفريد على مستوى المشروع — يمنع تقييمين لنفس المشروع في النافذة.
     */
    public function uniqueId(): string
    {
        $evaluation = Evaluation::find($this->evaluationId);

        return 'evaluation:'.($evaluation?->project_id ?? $this->evaluationId);
    }

    public function handle(EvaluationService $service): void
    {
        $evaluation = Evaluation::find($this->evaluationId);

        if (! $evaluation) {
            return;
        }

        $service->runEvaluation($evaluation);
    }
}
