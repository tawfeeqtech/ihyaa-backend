<?php

namespace App\Events;

use App\Models\Evaluation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * فشل جميع مزوّدي الذكاء الاصطناعي معاً — plan.md §3.2 (FR-222 · SRS-AI-F04).
 *
 * يُطلق من EvaluationService::markFailed عندما يكون السبب AllProvidersFailedException
 * (بعد استنفاد الأساسي والاحتياطي وكل المحاولات). هذا تنبيه داخلي للمشرفين
 * (Notification + Log) — لا يُبث للمستخدم ولا يظهر له أي خطأ تقني خام.
 */
class AllAiProvidersFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<\App\Exceptions\Ai\ProviderException>  $failures  إخفاقات كل المزوّدين (للمعايرة)
     */
    public function __construct(
        public Evaluation $evaluation,
        public array $failures = [],
    ) {
    }
}
