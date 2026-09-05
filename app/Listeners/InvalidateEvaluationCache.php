<?php

namespace App\Listeners;

use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Events\EvaluationPartial;
use App\Events\ProjectContentChanged;
use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationCacheService;

/**
 * إبطال كاش التقييم — plan.md §4.3 (SRS-AI-C01..C03 · FR-216).
 *
 * | الحدث | الإجراء |
 * |---|---|
 * | اكتمال تقييم جديد | result + cooldown طازجان من الخدمة — لا إبطال |
 * | تقييم `failed` | لا cooldown — إزالة أي مؤقّت قديم (SRS-AI-E05) |
 * | تقييم جزئي | يخزّن نتيجة + مؤقّت 1h — لا إبطال |
 * | "ليس الآن" (US-022-S3) | لا إبطال (SRS-AI-C02) — الحذف عند تأكيد إعادة التقييم فقط |
 *
 * الحارس `instanceof Evaluation` يمنع إعادة المعالجة عند مرور `broadcast()` بحمولة Notification.
 */
class InvalidateEvaluationCache
{
    public function __construct(
        private readonly EvaluationCacheService $cacheService,
    ) {
    }

    public function handleEvaluationCompleted(EvaluationCompleted $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        // result/cooldown أُنتجا في EvaluationService::complete — لا إبطال إضافي.
    }

    public function handleEvaluationFailed(EvaluationFailed $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        $this->cacheService->forgetProject((int) $event->evaluation->project_id);
    }

    public function handleEvaluationPartial(EvaluationPartial $event): void
    {
        if (! $event->evaluation instanceof Evaluation) {
            return;
        }

        // نتيجة جزئية + مؤقّت 1h مخزَّنان — لا إبطال.
    }

    public function handleProjectContentChanged(ProjectContentChanged $event): void
    {
        // SRS-AI-C02: تغيّر المحتوى دون تأكيد ← يبقى الكاش سارياً.
    }
}
