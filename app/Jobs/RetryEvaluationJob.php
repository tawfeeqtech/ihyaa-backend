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
 * إعادة محاولة تقييم فاشل/جزئي — plan.md §2.1 (US-019 · SRS-AI-E05).
 *
 * القناة `ai-evaluation` · tries=1 · timeout=200s · فريد بمفتاح retry:{evaluation_id}
 * يمنع تكديس محاولات مكررة لنفس سجل التقييم.
 */
class RetryEvaluationJob implements ShouldQueue, ShouldBeUnique
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

    public function uniqueId(): string
    {
        return 'retry:'.$this->evaluationId;
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
