<?php

namespace App\Observers;

use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Events\EvaluationPartial;
use App\Models\Evaluation;

/**
 * مرصاد التقييم — يطلق الأحداث النهائية عند تغيّر الحالة (T050 · plan.md §1.6).
 *
 * المسؤولية الوحيدة: عند `saved` بانتقال الحالة إلى حالة نهائية
 * (completed/failed/partial) يطلق الحدث المطابق — نقطة انطلاق موحّدة
 * يستمع إليها SendEvaluationNotification وSyncProjectToSearch وInvalidateEvaluationCache.
 *
 * - الحارس `wasChanged('status')`: يضمن إطلاق الحدث مرة واحدة عند الانتقال فقط
 *   (لا يُعاد عند كل حفظ لاحق — مثلاً increment retry_count في /retry).
 * - المصدر الوحيد للأحداث: أُزيل التصريح المباشر من EvaluationService
 *   (كان يُطلق الحدث بعد الالتزام) — لا إطلاق مزدوج.
 *
 * @final
 */
final class EvaluationObserver
{
    public function saved(Evaluation $evaluation): void
    {
        // لم تتغير الحالة (حفظ عام) — لا حدث (منع التكرار في /retry).
        if (! $evaluation->wasChanged('status')) {
            return;
        }

        match ($evaluation->status) {
            EvaluationStatus::COMPLETED => EvaluationCompleted::dispatch($evaluation),
            EvaluationStatus::PARTIAL => EvaluationPartial::dispatch($evaluation),
            EvaluationStatus::FAILED => EvaluationFailed::dispatch($evaluation),
            default => null, // pending/processing — لا حدث نهائي
        };
    }
}
